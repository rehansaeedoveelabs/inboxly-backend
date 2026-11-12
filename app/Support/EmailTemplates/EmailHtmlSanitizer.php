<?php

namespace App\Support\EmailTemplates;

/**
 * Conservative HTML sanitizer for email text blocks.
 * Strips scripts, event handlers, and dangerous URLs without external dependencies.
 */
class EmailHtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'span', 'a',
        'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'div',
    ];

    public function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? '';
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html) ?? '';
        $html = preg_replace('/\son[a-z]+\s*=\s*("|\').*?\1/iu', '', $html) ?? '';
        $html = preg_replace('/\son[a-z]+\s*=\s*[^\s>]+/iu', '', $html) ?? '';

        $allowed = implode('|', self::ALLOWED_TAGS);
        $html = preg_replace('#</?(?!('.$allowed.')\b)[a-z0-9-]+[^>]*>#iu', '', $html) ?? '';

        return $this->sanitizeAnchors($html);
    }

    public function sanitizeUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (preg_match('#^(https?:|mailto:)#i', $url) !== 1) {
            return null;
        }

        if (preg_match('#^(javascript|data|vbscript):#i', $url) === 1) {
            return null;
        }

        return $url;
    }

    private function sanitizeAnchors(string $html): string
    {
        return preg_replace_callback(
            '#<a\b([^>]*)>#iu',
            function (array $matches) {
                $attrs = $matches[1];
                if (! preg_match('/\bhref\s*=\s*("|\')(.*?)\1/iu', $attrs, $hrefMatch)) {
                    return '<a>';
                }
                $safe = $this->sanitizeUrl(html_entity_decode($hrefMatch[2], ENT_QUOTES | ENT_HTML5));
                if ($safe === null) {
                    return '<a>';
                }

                return '<a href="'.e($safe).'" target="_blank" rel="noopener noreferrer">';
            },
            $html,
        ) ?? $html;
    }
}
