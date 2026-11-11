<?php

namespace App\Support\EmailTemplates;

use App\Models\Asset;
use App\Models\Store;

/**
 * Renders a validated block document to sanitized HTML for email preview/storage.
 */
class EmailDocumentRenderer
{
    public function __construct(private EmailHtmlSanitizer $sanitizer) {}

    /**
     * @param  array<string, mixed>  $document
     */
    public function render(Store $store, array $document, ?string $assetUrlResolver = null): string
    {
        $settings = is_array($document['settings'] ?? null) ? $document['settings'] : [];
        $width = isset($settings['width']) && is_int($settings['width']) ? $settings['width'] : 600;
        $background = is_string($settings['background'] ?? null) ? $settings['background'] : '#ffffff';
        $blocks = is_array($document['blocks'] ?? null) ? $document['blocks'] : [];

        $parts = [];
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            $html = $this->renderBlock($store, $block, $assetUrlResolver);
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
    private function renderBlock(Store $store, array $block, ?string $assetUrlResolver): string
    {
        $type = is_string($block['type'] ?? null) ? $block['type'] : '';
        $props = is_array($block['props'] ?? null) ? $block['props'] : [];

        return match ($type) {
            'text', 'header', 'footer' => $this->renderText($props, $type === 'header' ? '24px' : ($type === 'footer' ? '12px' : '16px')),
            'image' => $this->renderImage($store, $props, $assetUrlResolver),
            'button' => $this->renderButton($props),
            'divider' => '<tr><td style="padding:16px;"><hr style="border:none;border-top:1px solid #dddddd;"></td></tr>',
            'spacer' => $this->renderSpacer($props),
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function renderText(array $props, string $fontSize): string
    {
        $html = $this->sanitizer->sanitize(is_string($props['html'] ?? null) ? $props['html'] : '');
        $align = in_array($props['align'] ?? 'left', ['left', 'center', 'right'], true) ? $props['align'] : 'left';

        return '<tr><td style="padding:12px 24px;font-family:Arial,sans-serif;font-size:'.$fontSize.';line-height:1.5;color:#222222;text-align:'.$align.';">'
            .$html
            .'</td></tr>';
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function renderImage(Store $store, array $props, ?string $assetUrlResolver): string
    {
        $assetId = is_string($props['asset_id'] ?? null) ? $props['asset_id'] : null;
        $alt = is_string($props['alt'] ?? null) ? $props['alt'] : '';
        $href = $this->sanitizer->sanitizeUrl(is_string($props['href'] ?? null) ? $props['href'] : null);

        if ($assetId === null || $assetId === '') {
            return '';
        }

        $asset = Asset::query()
            ->where('store_id', $store->getKey())
            ->whereKey($assetId)
            ->first();

        if ($asset === null) {
            return '';
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
}
