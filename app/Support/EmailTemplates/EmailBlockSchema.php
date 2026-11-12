<?php

namespace App\Support\EmailTemplates;

/**
 * Validates the block-document JSON used as the template source of truth.
 * Product blocks are intentionally unsupported until Shopify product sync exists.
 */
class EmailBlockSchema
{
    public const ALLOWED_TYPES = [
        'text', 'image', 'button', 'divider', 'spacer', 'header', 'footer',
    ];

    /**
     * @return list<string> validation error messages (empty = valid)
     */
    public static function validate(mixed $document): array
    {
        if (! is_array($document)) {
            return ['Document must be an object.'];
        }

        $errors = [];
        $version = $document['version'] ?? null;
        if ($version !== 1 && $version !== '1') {
            $errors[] = 'Document version must be 1.';
        }

        $blocks = $document['blocks'] ?? null;
        if (! is_array($blocks)) {
            $errors[] = 'Document blocks must be a list.';

            return $errors;
        }

        if (count($blocks) > 100) {
            $errors[] = 'A template may contain at most 100 blocks.';
        }

        foreach (array_values($blocks) as $index => $block) {
            if (! is_array($block)) {
                $errors[] = "Block {$index} must be an object.";

                continue;
            }

            $type = $block['type'] ?? null;
            if (! is_string($type) || ! in_array($type, self::ALLOWED_TYPES, true)) {
                $errors[] = "Block {$index} has an unsupported type.";

                continue;
            }

            if ($type === 'product') {
                $errors[] = "Block {$index}: product blocks are not available yet.";
            }

            $id = $block['id'] ?? null;
            if (! is_string($id) || trim($id) === '') {
                $errors[] = "Block {$index} requires a string id.";
            }

            $props = $block['props'] ?? [];
            if (! is_array($props)) {
                $errors[] = "Block {$index} props must be an object.";

                continue;
            }

            $errors = [...$errors, ...self::validateProps($type, $props, $index)];
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $props
     * @return list<string>
     */
    private static function validateProps(string $type, array $props, int $index): array
    {
        return match ($type) {
            'text', 'header', 'footer' => self::requireString($props, 'html', $index, allowEmpty: true),
            'image' => [
                ...self::optionalString($props, 'asset_id', $index),
                ...self::optionalString($props, 'alt', $index),
                ...self::optionalString($props, 'href', $index),
            ],
            'button' => [
                ...self::requireString($props, 'label', $index),
                ...self::requireString($props, 'href', $index),
            ],
            'divider' => [],
            'spacer' => self::optionalInt($props, 'height', $index, 8, 120),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $props
     * @return list<string>
     */
    private static function requireString(array $props, string $key, int $index, bool $allowEmpty = false): array
    {
        $value = $props[$key] ?? null;
        if (! is_string($value) || (! $allowEmpty && trim($value) === '')) {
            return ["Block {$index} requires props.{$key}."];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $props
     * @return list<string>
     */
    private static function optionalString(array $props, string $key, int $index): array
    {
        if (! array_key_exists($key, $props)) {
            return [];
        }
        if ($props[$key] !== null && ! is_string($props[$key])) {
            return ["Block {$index} props.{$key} must be a string."];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $props
     * @return list<string>
     */
    private static function optionalInt(array $props, string $key, int $index, int $min, int $max): array
    {
        if (! array_key_exists($key, $props) || $props[$key] === null) {
            return [];
        }
        if (! is_int($props[$key]) || $props[$key] < $min || $props[$key] > $max) {
            return ["Block {$index} props.{$key} must be an integer between {$min} and {$max}."];
        }

        return [];
    }
}
