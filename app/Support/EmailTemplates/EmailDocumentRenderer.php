<?php

namespace App\Support\EmailTemplates;

use App\Models\Asset;
use App\Models\ShopifyProduct;
use App\Models\Store;

/**
 * Renders a validated block document to sanitized HTML for email preview/storage.
 */
class EmailDocumentRenderer
{
    public function __construct(private EmailHtmlSanitizer $sanitizer) {}

    /**
     * @param  array<string, mixed>  $document
     * @param  array{show_empty_image_placeholders?: bool}|null  $options
     */
    public function render(
        Store $store,
        array $document,
        ?string $assetUrlResolver = null,
        ?array $options = null,
    ): string {
        $showEmptyImagePlaceholders = (bool) ($options['show_empty_image_placeholders'] ?? false);
        $settings = is_array($document['settings'] ?? null) ? $document['settings'] : [];
        $width = isset($settings['width']) && is_int($settings['width']) ? $settings['width'] : 600;
        $background = is_string($settings['background'] ?? null) ? $settings['background'] : '#ffffff';
        $blocks = is_array($document['blocks'] ?? null) ? $document['blocks'] : [];

        $parts = [];
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            $html = $this->renderBlock($store, $block, $assetUrlResolver, $showEmptyImagePlaceholders);
            if ($html !== '') {
                $parts[] = $html;
            }
        }

        $inner = implode("\n", $parts);

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>'
            .'<body style="margin:0;padding:0;background:#f4f4f4;">'
            .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f4f4;"><tr><td align="center">'
            .'<table role="presentation" width="'.(int) $width.'" cellspacing="0" cellpadding="0" style="width:'.(int) $width.'px;max-width:100%;background:'.e($background).';">'
            .$inner
            .'</table></td></tr></table></body></html>';
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function renderBlock(
        Store $store,
        array $block,
        ?string $assetUrlResolver,
        bool $showEmptyImagePlaceholders = false,
    ): string {
        $type = is_string($block['type'] ?? null) ? $block['type'] : '';
        $props = is_array($block['props'] ?? null) ? $block['props'] : [];

        return match ($type) {
            'text', 'header', 'footer' => $this->renderText(
                $props,
                $type === 'header' ? '24px' : ($type === 'footer' ? '12px' : '16px'),
                $type,
            ),
            'image' => $this->renderImage($store, $props, $assetUrlResolver, $showEmptyImagePlaceholders),
            'button' => $this->renderButton($props),
            'product' => $this->renderProduct($store, $props, $showEmptyImagePlaceholders),
            'divider' => '<tr><td style="padding:16px;"><hr style="border:none;border-top:1px solid #dddddd;"></td></tr>',
            'spacer' => $this->renderSpacer($props),
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function renderText(array $props, string $fontSize, string $type = 'text'): string
    {
        $html = $this->sanitizer->sanitize(is_string($props['html'] ?? null) ? $props['html'] : '');
        $align = in_array($props['align'] ?? 'left', ['left', 'center', 'right'], true) ? $props['align'] : 'left';

        // Header banners often include their own full-bleed padding/background —
        // skip outer cell padding so preview cards are not clipped.
        $padding = $type === 'header' ? '0' : '12px 24px';

        return '<tr><td style="padding:'.$padding.';font-family:Arial,sans-serif;font-size:'.$fontSize.';line-height:1.5;color:#222222;text-align:'.$align.';">'
            .$html
            .'</td></tr>';
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function renderImage(
        Store $store,
        array $props,
        ?string $assetUrlResolver,
        bool $showEmptyPlaceholders = false,
    ): string {
        $assetId = is_string($props['asset_id'] ?? null) ? $props['asset_id'] : null;
        $alt = is_string($props['alt'] ?? null) ? $props['alt'] : '';
        $href = $this->sanitizer->sanitizeUrl(is_string($props['href'] ?? null) ? $props['href'] : null);

        if ($assetId === null || $assetId === '') {
            return $showEmptyPlaceholders ? $this->emptyImagePlaceholder() : '';
        }

        $asset = Asset::query()
            ->where('store_id', $store->getKey())
            ->whereKey($assetId)
            ->first();

        if ($asset === null) {
            return $showEmptyPlaceholders ? $this->emptyImagePlaceholder('Image missing from library') : '';
        }

        $src = $assetUrlResolver
            ? str_replace('{id}', urlencode($asset->getKey()), $assetUrlResolver)
            : '';

        if ($src === '') {
            // Stored HTML uses a placeholder the SPA can rewrite for authenticated preview.
            $src = 'inboxly-asset://'.$asset->getKey();
        }

        $img = '<img src="'.e($src).'" alt="'.e($alt).'" style="display:block;max-width:100%;height:auto;border:0;">';
        if ($href !== null) {
            $img = '<a href="'.e($href).'" target="_blank" rel="noopener noreferrer">'.$img.'</a>';
        }

        return '<tr><td style="padding:12px 24px;" align="center">'.$img.'</td></tr>';
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function renderButton(array $props): string
    {
        $label = is_string($props['label'] ?? null) ? trim($props['label']) : '';
        $href = $this->sanitizer->sanitizeUrl(is_string($props['href'] ?? null) ? $props['href'] : null);
        if ($label === '' || $href === null) {
            return '';
        }

        return '<tr><td style="padding:16px 24px;" align="center">'
            .'<a href="'.e($href).'" target="_blank" rel="noopener noreferrer" '
            .'style="display:inline-block;background:#2878FF;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:6px;font-family:Arial,sans-serif;font-size:16px;">'
            .e($label)
            .'</a></td></tr>';
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function renderSpacer(array $props): string
    {
        $height = isset($props['height']) && is_int($props['height']) ? $props['height'] : 24;
        $height = max(8, min(120, $height));

        return '<tr><td style="height:'.$height.'px;line-height:'.$height.'px;font-size:1px;">&nbsp;</td></tr>';
    }

    /**
     * Shopify product card: featured image + title + shop CTA.
     *
     * @param  array<string, mixed>  $props
     */
    private function renderProduct(Store $store, array $props, bool $showEmptyPlaceholders = false): string
    {
        $productId = is_string($props['shopify_product_id'] ?? null) ? trim($props['shopify_product_id']) : '';
        $title = is_string($props['title'] ?? null) ? trim($props['title']) : '';
        $imageUrl = is_string($props['image_url'] ?? null) ? trim($props['image_url']) : '';
        $price = is_string($props['price'] ?? null) ? trim($props['price']) : '';
        $currencyCode = is_string($props['currency_code'] ?? null) ? trim($props['currency_code']) : '';
        $productUrl = $this->sanitizer->sanitizeUrl(
            is_string($props['product_url'] ?? null) ? $props['product_url'] : null,
        );
        $buttonLabel = is_string($props['button_label'] ?? null) && trim($props['button_label']) !== ''
            ? trim($props['button_label'])
            : 'Shop now';
        $showTitle = ! array_key_exists('show_title', $props) || $props['show_title'] !== false;
        $showPrice = ! array_key_exists('show_price', $props) || $props['show_price'] !== false;
        $showButton = ! array_key_exists('show_button', $props) || $props['show_button'] !== false;

        if ($productId !== '') {
            $product = ShopifyProduct::query()
                ->where('store_id', $store->getKey())
                ->whereKey($productId)
                ->first();
            if ($product !== null) {
                if ($title === '' && is_string($product->title)) {
                    $title = $product->title;
                }
                $meta = is_array($product->metadata) ? $product->metadata : [];
                if ($imageUrl === '' && isset($meta['featured_image_url']) && is_string($meta['featured_image_url'])) {
                    $imageUrl = $meta['featured_image_url'];
                }
                if ($price === '' && isset($meta['price_min']) && is_string($meta['price_min'])) {
                    $price = $meta['price_min'];
                    if (
                        isset($meta['price_max']) && is_string($meta['price_max'])
                        && $meta['price_max'] !== $meta['price_min']
                    ) {
                        $price = $meta['price_min'].' – '.$meta['price_max'];
                    }
                }
                if ($currencyCode === '' && isset($meta['currency_code']) && is_string($meta['currency_code'])) {
                    $currencyCode = $meta['currency_code'];
                }
                if ($productUrl === null) {
                    $productUrl = $this->sanitizer->sanitizeUrl($store->storefrontProductUrl($product->handle));
                }
            }
        }

        if ($productId === '' && $title === '' && $imageUrl === '') {
            return $showEmptyPlaceholders
                ? $this->emptyImagePlaceholder('Choose a Shopify product for this block')
                : '';
        }

        $parts = [];
        if ($imageUrl !== '' && $this->isHttpUrl($imageUrl)) {
            $img = '<img src="'.e($imageUrl).'" alt="'.e($title !== '' ? $title : 'Product').'" '
                .'style="display:block;max-width:100%;height:auto;border:0;border-radius:8px;">';
            if ($productUrl !== null) {
                $img = '<a href="'.e($productUrl).'" target="_blank" rel="noopener noreferrer">'.$img.'</a>';
            }
            $parts[] = $img;
        } elseif ($showEmptyPlaceholders) {
            $parts[] = '<div style="border:2px dashed #94a3b8;border-radius:12px;background:#f8fafc;color:#64748b;'
                .'font-family:Arial,sans-serif;font-size:13px;padding:28px 16px;text-align:center;">No product image</div>';
        }

        if ($showTitle && $title !== '') {
            $titleHtml = '<div style="font-family:Arial,sans-serif;font-size:18px;font-weight:700;color:#111111;margin-top:12px;">'
                .e($title)
                .'</div>';
            if ($productUrl !== null) {
                $titleHtml = '<a href="'.e($productUrl).'" target="_blank" rel="noopener noreferrer" '
                    .'style="color:#111111;text-decoration:none;">'.$titleHtml.'</a>';
            }
            $parts[] = $titleHtml;
        }

        if ($showPrice && $price !== '') {
            $priceLabel = $currencyCode !== '' ? $currencyCode.' '.$price : $price;
            $parts[] = '<div style="font-family:Arial,sans-serif;font-size:15px;font-weight:600;color:#334155;margin-top:6px;">'
                .e($priceLabel)
                .'</div>';
        }

        if ($showButton && $productUrl !== null) {
            $parts[] = '<div style="margin-top:14px;">'
                .'<a href="'.e($productUrl).'" target="_blank" rel="noopener noreferrer" '
                .'style="display:inline-block;background:#2878FF;color:#ffffff;text-decoration:none;padding:10px 18px;'
                .'border-radius:6px;font-family:Arial,sans-serif;font-size:15px;">'
                .e($buttonLabel)
                .'</a></div>';
        }

        if ($parts === []) {
            return $showEmptyPlaceholders
                ? $this->emptyImagePlaceholder('Choose a Shopify product for this block')
                : '';
        }

        return '<tr><td style="padding:16px 24px;" align="center">'.implode('', $parts).'</td></tr>';
    }

    private function isHttpUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array(is_string($scheme) ? strtolower($scheme) : '', ['http', 'https'], true);
    }

    /**
     * Studio-only marker so empty image blocks are visible in preview.
     * Never used for persisted campaign HTML (preview endpoint only).
     */
    private function emptyImagePlaceholder(string $label = 'Choose an image for this block'): string
    {
        return '<tr><td style="padding:16px 24px;" align="center">'
            .'<div style="border:2px dashed #94a3b8;border-radius:12px;background:#f8fafc;color:#64748b;'
            .'font-family:Arial,sans-serif;font-size:14px;line-height:1.4;padding:36px 20px;text-align:center;">'
            .e($label)
            .'<div style="margin-top:6px;font-size:12px;color:#94a3b8;">Click a library thumbnail or drop a file in Image settings</div>'
            .'</div></td></tr>';
    }
}
