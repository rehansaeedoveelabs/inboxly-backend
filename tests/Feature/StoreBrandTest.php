<?php

namespace Tests\Feature;

use App\Enums\StoreConnectionStatus;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class StoreBrandTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    public function test_brand_get_and_update_persists_in_metadata(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $this->spa()->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/brand")
            ->assertOk()
            ->assertJsonPath('data.id', $store->id);

        $this->spa()->actingAs($admin)
            ->putJson("/api/v1/stores/{$store->id}/brand", [
                'colors' => [
                    'accent' => '#112233',
                    'dark' => '#000000',
                    'light' => '#FFFFFF',
                ],
                'fonts' => [
                    'headings' => 'Georgia',
                    'body' => 'Inter',
                ],
                'social_links' => [
                    ['network' => 'Instagram', 'url' => 'https://instagram.com/acme'],
                ],
                'logo_name' => 'logo.png',
            ])
            ->assertOk()
            ->assertJsonPath('data.metadata.brand.colors.accent', '#112233')
            ->assertJsonPath('data.metadata.brand.fonts.headings', 'Georgia')
            ->assertJsonPath('data.metadata.brand.social_links.0.network', 'Instagram')
            ->assertJsonPath('data.metadata.brand.logo_name', 'logo.png');

        $this->assertSame('#112233', $store->fresh()->metadata['brand']['colors']['accent']);
    }

    public function test_brand_requires_store_access(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$outsider] = $this->adminWithWorkspace('Other');

        $this->spa()->actingAs($outsider)
            ->putJson("/api/v1/stores/{$store->id}/brand", [
                'colors' => ['accent' => '#111111'],
            ])
            ->assertForbidden();
    }

    public function test_brand_import_without_shopify_applies_defaults(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $this->spa()->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/brand/import")
            ->assertOk()
            ->assertJsonPath('source', 'defaults')
            ->assertJsonPath('data.metadata.brand.colors.accent', '#00A3FF')
            ->assertJsonPath('data.metadata.brand.fonts.headings', 'Arial');

        $this->assertSame('#00A3FF', $store->fresh()->metadata['brand']['colors']['accent']);
    }

    public function test_brand_import_falls_back_to_shop_profile_when_brand_denied(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->withShopifyCredentials('brand-fallback.myshopify.com')->create([
            'workspace_id' => $workspace->getKey(),
            'connection_status' => StoreConnectionStatus::Connected,
            'shopify_scopes' => ['read_customers', 'write_customers', 'read_products', 'read_orders'],
        ]);

        Http::fake([
            'https://brand-fallback.myshopify.com/admin/api/2026-10/graphql.json' => function ($request) {
                $body = $request->body();
                if (str_contains($body, 'InboxlyShopBrand')) {
                    return Http::response([
                        'errors' => [
                            [
                                'message' => 'Access denied for brand field.',
                                'extensions' => ['code' => 'ACCESS_DENIED'],
                            ],
                        ],
                    ], 200);
                }

                return Http::response([
                    'data' => [
                        'shop' => [
                            'name' => 'Oveelab',
                            'email' => 'hello@oveelab.demo',
                            'url' => 'https://oveelab.myshopify.com',
                            'myshopifyDomain' => 'oveelab.myshopify.com',
                        ],
                    ],
                ]);
            },
        ]);

        $this->spa()->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/brand/import")
            ->assertOk()
            ->assertJsonPath('source', 'missing_themes_scope')
            ->assertJsonPath('data.metadata.brand.shop_name', 'Oveelab')
            ->assertJsonPath('data.metadata.brand.logo_name', 'Oveelab');
    }

    public function test_brand_import_pulls_colors_from_live_theme(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->withShopifyCredentials('brand-theme.myshopify.com')->create([
            'workspace_id' => $workspace->getKey(),
            'connection_status' => StoreConnectionStatus::Connected,
            'shopify_scopes' => [
                'read_customers',
                'write_customers',
                'read_products',
                'read_orders',
                'read_themes',
            ],
        ]);

        $settingsJson = json_encode([
            'current' => [
                'color_schemes' => [
                    'scheme-1' => [
                        'settings' => [
                            'background' => '#FFFFFF',
                            'text' => '#121212',
                            'button' => '#00A3FF',
                            'button_label' => '#FFFFFF',
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        Http::fake([
            'https://brand-theme.myshopify.com/admin/api/2026-10/graphql.json' => function ($request) use ($settingsJson) {
                $body = $request->body();
                if (str_contains($body, 'InboxlyShopBrand')) {
                    return Http::response([
                        'errors' => [
                            [
                                'message' => 'Access denied for brand field.',
                                'extensions' => ['code' => 'ACCESS_DENIED'],
                            ],
                        ],
                    ], 200);
                }
                if (str_contains($body, 'InboxlyMainThemeSettings')) {
                    return Http::response([
                        'data' => [
                            'themes' => [
                                'nodes' => [
                                    [
                                        'id' => 'gid://shopify/OnlineStoreTheme/1',
                                        'name' => 'Dawn',
                                        'files' => [
                                            'nodes' => [
                                                [
                                                    'filename' => 'config/settings_data.json',
                                                    'body' => ['content' => $settingsJson],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ]);
                }

                return Http::response([
                    'data' => [
                        'shop' => [
                            'name' => 'Theme Shop',
                            'email' => 'hello@example.com',
                            'url' => 'https://brand-theme.myshopify.com',
                            'myshopifyDomain' => 'brand-theme.myshopify.com',
                        ],
                    ],
                ]);
            },
        ]);

        $this->spa()->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/brand/import")
            ->assertOk()
            ->assertJsonPath('source', 'theme')
            ->assertJsonPath('data.metadata.brand.colors.accent', '#00A3FF')
            ->assertJsonPath('data.metadata.brand.colors.dark', '#121212')
            ->assertJsonPath('data.metadata.brand.colors.light', '#FFFFFF');
    }

    public function test_brand_import_pulls_shopify_brand_kit_when_available(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->withShopifyCredentials('brand-kit.myshopify.com')->create([
            'workspace_id' => $workspace->getKey(),
            'connection_status' => StoreConnectionStatus::Connected,
            'shopify_scopes' => [
                'read_customers',
                'write_customers',
                'read_products',
                'read_orders',
            ],
        ]);

        Http::fake([
            'https://brand-kit.myshopify.com/admin/api/2026-10/graphql.json' => Http::response([
                'data' => [
                    'shop' => [
                        'name' => 'Brand Kit Shop',
                        'brand' => [
                            'colors' => [
                                'primary' => [
                                    'background' => '#00A3FF',
                                    'foreground' => '#10131C',
                                ],
                                'secondary' => [
                                    'background' => 'AABBCC',
                                    'foreground' => null,
                                ],
                            ],
                            'logo' => [
                                'image' => [
                                    'url' => 'https://cdn.example/logo.png',
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->spa()->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/brand/import")
            ->assertOk()
            ->assertJsonPath('source', 'shopify')
            ->assertJsonPath('data.metadata.brand.colors.accent', '#00A3FF')
            ->assertJsonPath('data.metadata.brand.colors.dark', '#10131C')
            ->assertJsonPath('data.metadata.brand.imported_logo_url', 'https://cdn.example/logo.png');

        $this->assertContains('#AABBCC', $store->fresh()->metadata['brand']['colors']['additional']);
    }
}
