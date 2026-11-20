<?php

namespace App\Actions\Shopify;

use App\Exceptions\ShopifyAdminGraphqlException;
use App\Exceptions\ShopifyCredentialException;
use App\Models\Store;
use App\Services\Shopify\ShopifyAdminGraphqlClient;
use App\Services\Shopify\ShopifyCredentialService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Import brand kit into store.metadata.brand.
 *
 * Order:
 *  1) Soft-try shop.brand (often unavailable — Partner may not list read_brand)
 *  2) Pull colors from the live Online Store theme (needs read_themes)
 *  3) Fall back to shop name + starter colors
 *
 * Do not use Checkout Branding scopes — that is checkout UI, not store Brand assets.
 */
class ImportBrandAssetsFromShopify
{
    private const SHOP_QUERY = <<<'GRAPHQL'
    query InboxlyShopProfile {
      shop {
        name
        email
        url
        myshopifyDomain
      }
    }
    GRAPHQL;

    private const BRAND_QUERY = <<<'GRAPHQL'
    query InboxlyShopBrand {
      shop {
        name
        brand {
          colors {
            primary { background foreground }
            secondary { background foreground }
          }
          logo {
            image {
              url
            }
          }
        }
      }
    }
    GRAPHQL;

    private const THEME_SETTINGS_QUERY = <<<'GRAPHQL'
    query InboxlyMainThemeSettings {
      themes(first: 1, roles: [MAIN]) {
        nodes {
          id
          name
          files(filenames: ["config/settings_data.json"], first: 1) {
            nodes {
              filename
              body {
                ... on OnlineStoreThemeFileBodyText {
                  content
                }
              }
            }
          }
        }
      }
    }
    GRAPHQL;

    public function __construct(
        private readonly ShopifyCredentialService $credentials,
        private readonly ShopifyAdminGraphqlClient $graphql,
    ) {}

    /**
     * @return array{brand: array<string, mixed>, source: string, notice: string}
     */
    public function handle(Store $store): array
    {
        $existing = is_array($store->metadata['brand'] ?? null) ? $store->metadata['brand'] : [];
        $colors = is_array($existing['colors'] ?? null) ? $existing['colors'] : [];
        $fonts = is_array($existing['fonts'] ?? null) ? $existing['fonts'] : [];

        $brand = [
            'logo_asset_id' => $existing['logo_asset_id'] ?? null,
            'logo_name' => $existing['logo_name'] ?? null,
            'colors' => [
                'accent' => $colors['accent'] ?? '#00A3FF',
                'dark' => $colors['dark'] ?? '#000000',
                'light' => $colors['light'] ?? '#FFFFFF',
                'additional' => is_array($colors['additional'] ?? null) ? array_values($colors['additional']) : [],
            ],
            'fonts' => [
                'headings' => $fonts['headings'] ?? 'Arial',
                'body' => $fonts['body'] ?? 'Arial',
            ],
            'social_links' => is_array($existing['social_links'] ?? null) ? $existing['social_links'] : [],
            'imported_logo_url' => $existing['imported_logo_url'] ?? null,
            'shop_name' => is_string($existing['shop_name'] ?? null) ? $existing['shop_name'] : null,
        ];

        if (! $store->isConnected() || ! $store->hasShopifyCredentials()) {
            $this->persist($store, $brand);

            return [
                'brand' => $brand,
                'source' => 'defaults',
                'notice' => 'Shopify is not connected. Connect Shopify, then import again.',
            ];
        }

        try {
            $token = $this->credentials->ensureValidAccessToken($store);
            $domain = (string) ($store->shopify_shop_domain ?: $store->domain);

            $shopName = null;
            $brandKit = $this->tryBrandKit($domain, $token);
            if ($brandKit !== null) {
                $shopName = $brandKit['shop_name'];
                $applied = $this->applyBrandKit($brand, $brandKit['brand']);
                if ($shopName) {
                    $brand['shop_name'] = $shopName;
                }
                if ($applied) {
                    $this->persist($store, $brand);

                    return [
                        'brand' => $brand,
                        'source' => 'shopify',
                        'notice' => 'Imported brand colors'.($shopName ? " from {$shopName}" : '').'. Upload a logo in Inboxly if you want it stored for emails.',
                    ];
                }
            }

            $themeResult = $this->tryThemeColors($store, $domain, $token, $brand);
            if ($themeResult['applied']) {
                $this->persist($store, $brand);

                return [
                    'brand' => $brand,
                    'source' => 'theme',
                    'notice' => $themeResult['notice'],
                ];
            }

            $profile = $this->fetchShopProfile($domain, $token);
            $shopName = $profile['name'] ?: $shopName;
            if (is_string($shopName) && $shopName !== '') {
                $brand['shop_name'] = $shopName;
                if (empty($brand['logo_name'])) {
                    $brand['logo_name'] = $shopName;
                }
            }

            $this->persist($store, $brand);

            $needsThemes = ! $store->hasShopifyScope('read_themes');

            return [
                'brand' => $brand,
                'source' => $needsThemes ? 'missing_themes_scope' : 'shop_profile',
                'notice' => $needsThemes
                    ? 'Imported store name'
                        .($shopName ? " ({$shopName})" : '')
                        .'. To pull theme colors, enable read_themes in the Partner app Admin API scopes, reconnect Shopify, then Import again.'
                    : 'Imported store profile'
                        .($shopName ? " ({$shopName})" : '')
                        .'. Couldn’t read theme colors from the live theme — set accent/dark/light manually, then Save.',
            ];
        } catch (ShopifyCredentialException|ShopifyAdminGraphqlException $e) {
            Log::info('Shopify brand import fell back.', [
                'store_id' => $store->getKey(),
                'reason' => $e->getMessage(),
                'code' => $e->errorCode ?? null,
            ]);
            $this->persist($store, $brand);

            return [
                'brand' => $brand,
                'source' => 'defaults',
                'notice' => 'Could not reach Shopify for brand import. Edit colors manually or reconnect and retry.',
            ];
        } catch (Throwable $e) {
            Log::warning('Unexpected Shopify brand import failure.', [
                'store_id' => $store->getKey(),
                'message' => $e->getMessage(),
            ]);
            $this->persist($store, $brand);

            return [
                'brand' => $brand,
                'source' => 'defaults',
                'notice' => 'Brand import failed unexpectedly. Set colors manually.',
            ];
        }
    }

    /**
     * @return array{shop_name: ?string, brand: array<string, mixed>}|null
     */
    private function tryBrandKit(string $domain, string $token): ?array
    {
        try {
            $data = $this->graphql->request($domain, $token, self::BRAND_QUERY);
            $shop = is_array($data['shop'] ?? null) ? $data['shop'] : [];
            $shopBrand = is_array($shop['brand'] ?? null) ? $shop['brand'] : null;
            if ($shopBrand === null) {
                return null;
            }

            return [
                'shop_name' => is_string($shop['name'] ?? null) ? $shop['name'] : null,
                'brand' => $shopBrand,
            ];
        } catch (ShopifyAdminGraphqlException $e) {
            Log::info('shop.brand unavailable; trying theme colors.', [
                'code' => $e->errorCode ?? null,
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $brand
     * @return array{applied: bool, notice: string}
     */
    private function tryThemeColors(Store $store, string $domain, string $token, array &$brand): array
    {
        if (! $store->hasShopifyScope('read_themes')) {
            return ['applied' => false, 'notice' => ''];
        }

        try {
            $data = $this->graphql->request($domain, $token, self::THEME_SETTINGS_QUERY);
            $theme = is_array($data['themes']['nodes'][0] ?? null) ? $data['themes']['nodes'][0] : null;
            if ($theme === null) {
                return ['applied' => false, 'notice' => ''];
            }

            $raw = $theme['files']['nodes'][0]['body']['content'] ?? null;
            if (! is_string($raw) || trim($raw) === '') {
                return ['applied' => false, 'notice' => ''];
            }

            // Theme JSON often starts with /* comment */ — strip before decode.
            $json = preg_replace('#/\*.*?\*/#s', '', $raw) ?? $raw;
            $decoded = json_decode($json, true);
            if (! is_array($decoded)) {
                return ['applied' => false, 'notice' => ''];
            }

            $extracted = $this->extractColorsFromThemeSettings($decoded);
            if ($extracted === null) {
                return ['applied' => false, 'notice' => ''];
            }

            $brand['colors']['accent'] = $extracted['accent'];
            $brand['colors']['dark'] = $extracted['dark'];
            $brand['colors']['light'] = $extracted['light'];
            $brand['colors']['additional'] = $extracted['additional'];

            $themeName = is_string($theme['name'] ?? null) ? $theme['name'] : 'live theme';

            return [
                'applied' => true,
                'notice' => "Imported colors from your live theme ({$themeName}). Upload a logo in Inboxly if you want it stored for emails.",
            ];
        } catch (ShopifyAdminGraphqlException $e) {
            Log::info('Theme color import unavailable.', [
                'code' => $e->errorCode ?? null,
            ]);

            return ['applied' => false, 'notice' => ''];
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{accent: string, dark: string, light: string, additional: list<string>}|null
     */
    private function extractColorsFromThemeSettings(array $settings): ?array
    {
        $current = is_array($settings['current'] ?? null) ? $settings['current'] : $settings;
        $candidates = [];

        $schemes = $current['color_schemes'] ?? null;
        if (is_array($schemes)) {
            foreach ($schemes as $scheme) {
                $schemeSettings = is_array($scheme['settings'] ?? null) ? $scheme['settings'] : (is_array($scheme) ? $scheme : []);
                foreach (['button', 'button_label', 'text', 'background', 'secondary_button_label', 'shadow'] as $key) {
                    $hex = $this->normalizeHex((string) ($schemeSettings[$key] ?? ''));
                    if ($hex !== null) {
                        $candidates[$key] = $candidates[$key] ?? $hex;
                    }
                }
            }
        }

        foreach ([
            'colors_accent_1' => 'button',
            'colors_accent_2' => 'secondary',
            'colors_text' => 'text',
            'colors_solid_button_labels' => 'button_label',
            'colors_background_1' => 'background',
            'color_primary' => 'button',
            'color_secondary' => 'secondary',
            'color_text' => 'text',
            'color_background' => 'background',
        ] as $key => $role) {
            $hex = $this->normalizeHex((string) ($current[$key] ?? ''));
            if ($hex !== null && ! isset($candidates[$role])) {
                $candidates[$role] = $hex;
            }
        }

        // Last resort: collect any hex-looking values under current.
        if ($candidates === []) {
            $found = [];
            array_walk_recursive($current, function ($value) use (&$found) {
                if (! is_string($value)) {
                    return;
                }
                $hex = $this->normalizeHex($value);
                if ($hex !== null && ! in_array($hex, $found, true)) {
                    $found[] = $hex;
                }
            });
            if (count($found) < 2) {
                return null;
            }
            $candidates['background'] = $found[0];
            $candidates['text'] = $found[1];
            $candidates['button'] = $found[2] ?? $found[1];
        }

        $light = $candidates['background'] ?? '#FFFFFF';
        $dark = $candidates['text'] ?? '#000000';
        $accent = $candidates['button'] ?? $candidates['secondary'] ?? $dark;
        $additional = [];
        foreach ($candidates as $hex) {
            if ($hex !== $accent && $hex !== $dark && $hex !== $light && ! in_array($hex, $additional, true)) {
                $additional[] = $hex;
            }
        }

        return [
            'accent' => $accent,
            'dark' => $dark,
            'light' => $light,
            'additional' => array_slice($additional, 0, 6),
        ];
    }

    /**
     * @return array{name: ?string, email: ?string, url: ?string}
     */
    private function fetchShopProfile(string $domain, string $token): array
    {
        $data = $this->graphql->request($domain, $token, self::SHOP_QUERY);
        $shop = is_array($data['shop'] ?? null) ? $data['shop'] : [];

        return [
            'name' => is_string($shop['name'] ?? null) ? $shop['name'] : null,
            'email' => is_string($shop['email'] ?? null) ? $shop['email'] : null,
            'url' => is_string($shop['url'] ?? null) ? $shop['url'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $brand
     * @param  array<string, mixed>  $shopBrand
     */
    private function applyBrandKit(array &$brand, array $shopBrand): bool
    {
        $changed = false;
        $additional = $brand['colors']['additional'];

        $primaryBg = $this->normalizeHex((string) ($shopBrand['colors']['primary']['background'] ?? ''));
        $primaryFg = $this->normalizeHex((string) ($shopBrand['colors']['primary']['foreground'] ?? ''));
        $secondaryBg = $this->normalizeHex((string) ($shopBrand['colors']['secondary']['background'] ?? ''));
        $secondaryFg = $this->normalizeHex((string) ($shopBrand['colors']['secondary']['foreground'] ?? ''));

        if ($primaryBg !== null) {
            $brand['colors']['accent'] = $primaryBg;
            $changed = true;
        }
        if ($primaryFg !== null) {
            $brand['colors']['dark'] = $primaryFg;
            $changed = true;
        }
        if ($secondaryBg !== null) {
            if (! in_array($secondaryBg, $additional, true) && $secondaryBg !== $brand['colors']['accent']) {
                $additional[] = $secondaryBg;
            }
            $changed = true;
        }
        if ($secondaryFg !== null) {
            if (! in_array($secondaryFg, $additional, true) && $secondaryFg !== $brand['colors']['accent']) {
                $additional[] = $secondaryFg;
            }
            $changed = true;
        }
        $brand['colors']['additional'] = array_slice(array_values($additional), 0, 6);

        $logoUrl = $shopBrand['logo']['image']['url'] ?? null;
        if (is_string($logoUrl) && $logoUrl !== '') {
            $brand['imported_logo_url'] = $logoUrl;
            if (empty($brand['logo_name'])) {
                $brand['logo_name'] = 'Shopify brand logo';
            }
            $changed = true;
        }

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $brand
     */
    private function persist(Store $store, array $brand): void
    {
        $metadata = is_array($store->metadata) ? $store->metadata : [];
        $metadata['brand'] = $brand;
        $store->metadata = $metadata;
        $store->save();
    }

    private function normalizeHex(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if ($value[0] !== '#') {
            $value = '#'.$value;
        }
        if (preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $value) !== 1) {
            return null;
        }
        if (strlen($value) === 4) {
            return strtoupper(sprintf(
                '#%s%s%s%s%s%s',
                $value[1],
                $value[1],
                $value[2],
                $value[2],
                $value[3],
                $value[3],
            ));
        }

        return strtoupper($value);
    }
}
