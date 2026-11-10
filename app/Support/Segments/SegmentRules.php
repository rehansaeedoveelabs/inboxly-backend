<?php

namespace App\Support\Segments;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;

/**
 * Schema for segment rules (flat list of conditions, one AND/OR operator):
 *
 *   {
 *     "operator": "and" | "or",
 *     "conditions": [
 *       {"field": "email",         "op": "contains", "value": "@gmail.com"},
 *       {"field": "tag",           "op": "in",       "value": ["vip"]},
 *       {"field": "consent.email", "op": "eq",       "value": "subscribed"},
 *       {"field": "phone",         "op": "is_null"}
 *     ]
 *   }
 *
 * Fields: email, phone, first_name, last_name, source, tag (slug), consent.email|sms|push.
 * Ops:    eq, neq, contains, starts_with, in, not_in, is_null, is_not_null.
 *
 * Semantics worth knowing:
 *  - neq / not_in also match rows where the column is NULL.
 *  - tag conditions test the contact's set of tags: "in" = has any of; "not_in"/"neq" = has none of;
 *    is_null = has no tags; is_not_null = has at least one tag.
 *  - consent.<channel> compares the current status; a contact with no consent row for the channel
 *    only matches neq / not_in / is_null.
 */
final class SegmentRules
{
    public const MAX_CONDITIONS = 20;

    public const MAX_LIST_VALUES = 100;

    public const OPERATORS = ['and', 'or'];

    public const COLUMN_FIELDS = ['email', 'phone', 'first_name', 'last_name', 'source'];

    public const TAG_FIELD = 'tag';

    public const OPS = ['eq', 'neq', 'contains', 'starts_with', 'in', 'not_in', 'is_null', 'is_not_null'];

    private const CONSENT_OPS = ['eq', 'neq', 'in', 'not_in', 'is_null', 'is_not_null'];

    private const LIST_OPS = ['in', 'not_in'];

    private const NULL_OPS = ['is_null', 'is_not_null'];

    /** @return list<string> */
    public static function fields(): array
    {
        return [
            ...self::COLUMN_FIELDS,
            self::TAG_FIELD,
            ...array_map(fn (ConsentChannel $c) => 'consent.'.$c->value, ConsentChannel::cases()),
        ];
    }

    public static function isConsentField(string $field): bool
    {
        return str_starts_with($field, 'consent.')
            && ConsentChannel::tryFrom(substr($field, 8)) !== null;
    }

    public static function isKnownField(mixed $field): bool
    {
        return is_string($field) && in_array($field, self::fields(), true);
    }

    /** Whether $op may be used with $field (ignores the value). */
    public static function opAllowedFor(string $field, string $op): bool
    {
        if (! in_array($op, self::OPS, true)) {
            return false;
        }

        return ! self::isConsentField($field) || in_array($op, self::CONSENT_OPS, true);
    }

    /**
     * @return array<string, string> dot-path => message; empty when valid
     */
    public static function validate(mixed $rules): array
    {
        if (! is_array($rules)) {
            return ['' => 'Rules must be an object.'];
        }

        $errors = [];

        if (! isset($rules['operator']) || ! in_array($rules['operator'], self::OPERATORS, true)) {
            $errors['operator'] = 'The operator must be "and" or "or".';
        }

        $conditions = $rules['conditions'] ?? null;

        if (! is_array($conditions) || ! array_is_list($conditions)) {
            $errors['conditions'] = 'Conditions must be a list.';

            return $errors;
        }

        if (count($conditions) > self::MAX_CONDITIONS) {
            $errors['conditions'] = 'A segment can have at most '.self::MAX_CONDITIONS.' conditions.';

            return $errors;
        }

        foreach ($conditions as $i => $condition) {
            $path = "conditions.$i";

            if (! is_array($condition)) {
                $errors[$path] = 'Each condition must be an object.';

                continue;
            }

            $field = $condition['field'] ?? null;
            $op = $condition['op'] ?? null;

            if (! self::isKnownField($field)) {
                $errors["$path.field"] = 'Unknown field.';

                continue;
            }

            if (! is_string($op) || ! self::opAllowedFor($field, $op)) {
                $errors["$path.op"] = 'Unsupported operator for this field.';

                continue;
            }

            if ($message = self::validateValue($field, $op, $condition['value'] ?? null)) {
                $errors["$path.value"] = $message;
            }
        }

        return $errors;
    }

    /**
     * Strips unknown keys so only the documented shape is persisted. Call after validate().
     *
     * @param  array<string, mixed>  $rules
     * @return array{operator: string, conditions: list<array<string, mixed>>}
     */
    public static function normalize(array $rules): array
    {
        $conditions = [];

        foreach ($rules['conditions'] ?? [] as $condition) {
            $normalized = ['field' => $condition['field'], 'op' => $condition['op']];

            if (! in_array($condition['op'], self::NULL_OPS, true)) {
                $normalized['value'] = $condition['value'];
            }

            $conditions[] = $normalized;
        }

        return ['operator' => $rules['operator'], 'conditions' => $conditions];
    }

    private static function validateValue(string $field, string $op, mixed $value): ?string
    {
        if (in_array($op, self::NULL_OPS, true)) {
            return null;
        }

        $isList = in_array($op, self::LIST_OPS, true);
        $values = $isList ? $value : [$value];

        if ($isList && (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > self::MAX_LIST_VALUES)) {
            return 'The value must be a non-empty list of at most '.self::MAX_LIST_VALUES.' items.';
        }

        foreach ($values as $item) {
            if (! is_string($item) || $item === '' || mb_strlen($item) > 255) {
                return 'Values must be non-empty strings of at most 255 characters.';
            }

            if (self::isConsentField($field) && ConsentStatus::tryFrom($item) === null) {
                return 'Consent values must be one of: '.implode(', ', array_column(ConsentStatus::cases(), 'value')).'.';
            }
        }

        return null;
    }
}
