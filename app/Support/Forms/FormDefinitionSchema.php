<?php

namespace App\Support\Forms;

/**
 * Validates the JSON definition used by the visual form editor.
 */
class FormDefinitionSchema
{
    /** Collectable inputs (public submit). */
    public const INPUT_TYPES = [
        'email', 'phone', 'consent', 'text', 'date', 'dropdown', 'radio', 'checkbox',
    ];

    /** Design / media / gamification canvas items. */
    public const DESIGN_TYPES = [
        'heading', 'button', 'image', 'spacer',
        'wheel', 'mystery', 'scratch', 'plinko', 'pick_card', 'countdown',
    ];

    /**
     * @return list<string>
     */
    public static function fieldTypes(): array
    {
        return array_values(array_unique([...self::INPUT_TYPES, ...self::DESIGN_TYPES]));
    }

    public static function isInputType(string $type): bool
    {
        return in_array($type, self::INPUT_TYPES, true);
    }

    /**
     * @return list<string>
     */
    public static function validate(mixed $definition): array
    {
        if (! is_array($definition)) {
            return ['Definition must be an object.'];
        }

        $errors = [];
        $allowedTypes = self::fieldTypes();

        foreach (['headline', 'subheadline', 'button_label'] as $key) {
            $value = $definition[$key] ?? null;
            if (! is_string($value) || mb_strlen($value) > 500) {
                $errors[] = "Definition.{$key} must be a string (max 500).";
            }
        }

        if (array_key_exists('layout', $definition)) {
            $layout = $definition['layout'];
            if (! is_string($layout) || ! in_array($layout, ['stack', 'split'], true)) {
                $errors[] = 'Definition.layout must be stack or split.';
            }
        }

        if (array_key_exists('tags', $definition)) {
            $tags = $definition['tags'];
            if (! is_array($tags) || count($tags) > 20) {
                $errors[] = 'Definition.tags must be a list of at most 20 strings.';
            } else {
                foreach ($tags as $i => $tag) {
                    if (! is_string($tag) || trim($tag) === '' || mb_strlen($tag) > 64) {
                        $errors[] = "Definition.tags.{$i} must be a non-empty string.";
                    }
                }
            }
        }

        if (array_key_exists('success', $definition)) {
            $success = $definition['success'];
            if (! is_array($success)) {
                $errors[] = 'Definition.success must be an object.';
            } else {
                foreach (['headline', 'subheadline', 'button_label'] as $key) {
                    if (! array_key_exists($key, $success)) {
                        continue;
                    }
                    $value = $success[$key];
                    if (! is_string($value) || mb_strlen($value) > 500) {
                        $errors[] = "Definition.success.{$key} must be a string (max 500).";
                    }
                }
            }
        }

        $fields = $definition['fields'] ?? null;
        if (! is_array($fields)) {
            $errors[] = 'Definition.fields must be a list.';
        } else {
            if (count($fields) > 40) {
                $errors[] = 'A form may contain at most 40 fields.';
            }
            foreach (array_values($fields) as $index => $field) {
                if (! is_array($field)) {
                    $errors[] = "Field {$index} must be an object.";

                    continue;
                }
                $id = $field['id'] ?? null;
                if (! is_string($id) || trim($id) === '') {
                    $errors[] = "Field {$index} requires a string id.";
                }
                $type = $field['type'] ?? null;
                if (! is_string($type) || ! in_array($type, $allowedTypes, true)) {
                    $errors[] = "Field {$index} has an unsupported type.";
                }
                $label = $field['label'] ?? null;
                if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 255) {
                    $errors[] = "Field {$index} requires a label.";
                }
                if (array_key_exists('required', $field) && ! is_bool($field['required'])) {
                    $errors[] = "Field {$index} required must be a boolean.";
                }
            }
        }

        $theme = $definition['theme'] ?? null;
        if (! is_array($theme)) {
            $errors[] = 'Definition.theme must be an object.';
        } else {
            foreach (['accent', 'background', 'text'] as $key) {
                $value = $theme[$key] ?? null;
                if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 64) {
                    $errors[] = "Definition.theme.{$key} must be a string.";
                }
            }
        }

        $behavior = $definition['behavior'] ?? null;
        if (! is_array($behavior)) {
            $errors[] = 'Definition.behavior must be an object.';
        } else {
            foreach (['display', 'devices', 'frequency'] as $key) {
                $value = $behavior[$key] ?? null;
                if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 120) {
                    $errors[] = "Definition.behavior.{$key} must be a string.";
                }
            }
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultDefinition(): array
    {
        return [
            'headline' => 'GET 10% OFF YOUR FIRST ORDER',
            'subheadline' => 'Join for drops, offers, and early access.',
            'button_label' => 'Subscribe',
            'layout' => 'stack',
            'tags' => ['form_subscriber'],
            'fields' => [
                [
                    'id' => 'f_email',
                    'type' => 'email',
                    'label' => 'Email',
                    'required' => true,
                ],
                [
                    'id' => 'f_consent',
                    'type' => 'consent',
                    'label' => 'I agree to receive marketing emails',
                    'required' => true,
                ],
            ],
            'theme' => [
                'accent' => '#2878FF',
                'background' => '#FFFFFF',
                'text' => '#10131C',
            ],
            'behavior' => [
                'display' => 'After spending 5 sec on the page',
                'devices' => 'Show on all devices',
                'frequency' => 'Show after 1 day',
                'scheduling' => 'Always available',
                'targeting' => 'All visitors',
                'redirect' => 'Stay on page',
            ],
            'success' => [
                'headline' => 'YOU’RE IN!',
                'subheadline' => 'Check your inbox for your offer.',
                'button_label' => 'Continue shopping',
            ],
        ];
    }
}
