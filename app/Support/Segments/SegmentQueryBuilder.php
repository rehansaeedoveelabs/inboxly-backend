<?php

namespace App\Support\Segments;

use App\Enums\ConsentChannel;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applies segment rules (see SegmentRules) to a Contact query, scoped to one store.
 * Nothing is materialised: membership is always computed from the live tables.
 *
 * Rules are validated on write. On read, anything unrecognised (unknown field/op, malformed value)
 * is skipped rather than throwing, so a stale row can never break a request.
 */
class SegmentQueryBuilder
{
    /**
     * @param  Builder<Contact>  $query
     * @param  array<string, mixed>  $rules
     * @return Builder<Contact>
     */
    public function apply(Builder $query, Store $store, array $rules): Builder
    {
        // The store scope is always ANDed outside the (possibly OR) rule group.
        $query->where($query->qualifyColumn('store_id'), $store->getKey());

        $conditions = is_array($rules['conditions'] ?? null) ? $rules['conditions'] : [];
        $boolean = ($rules['operator'] ?? 'and') === 'or' ? 'or' : 'and';

        $valid = array_values(array_filter($conditions, fn ($c) => $this->isApplicable($c)));

        if ($valid === []) {
            return $query;
        }

        return $query->where(function (Builder $group) use ($valid, $boolean) {
            foreach ($valid as $condition) {
                $group->where(
                    fn (Builder $q) => $this->applyCondition($q, $condition['field'], $condition['op'], $condition['value'] ?? null),
                    boolean: $boolean,
                );
            }
        });
    }

    /** @return Builder<Contact> */
    public function forSegment(Segment $segment, ?Builder $query = null): Builder
    {
        return $this->apply(
            $query ?? Contact::query(),
            $segment->store,
            $segment->rules ?? [],
        );
    }

    private function isApplicable(mixed $condition): bool
    {
        if (! is_array($condition) || ! isset($condition['field'], $condition['op'])) {
            return false;
        }

        if (! SegmentRules::isKnownField($condition['field']) || ! is_string($condition['op'])
            || ! SegmentRules::opAllowedFor($condition['field'], $condition['op'])) {
            return false;
        }

        return SegmentRules::validate([
            'operator' => 'and',
            'conditions' => [$condition],
        ]) === [];
    }

    private function applyCondition(Builder $q, string $field, string $op, mixed $value): void
    {
        if (in_array($field, SegmentRules::COLUMN_FIELDS, true)) {
            $this->applyColumn($q, $field, $op, $value);
        } elseif ($field === SegmentRules::TAG_FIELD) {
            $this->applyTag($q, $op, $value);
        } else {
            $this->applyConsent($q, ConsentChannel::from(substr($field, 8)), $op, $value);
        }
    }

    private function applyColumn(Builder $q, string $column, string $op, mixed $value): void
    {
        match ($op) {
            'eq' => $q->where($column, $value),
            'neq' => $q->where(fn (Builder $w) => $w->where($column, '!=', $value)->orWhereNull($column)),
            'contains' => $q->where($column, 'like', '%'.$this->escapeLike($value).'%'),
            'starts_with' => $q->where($column, 'like', $this->escapeLike($value).'%'),
            'in' => $q->whereIn($column, $value),
            'not_in' => $q->where(fn (Builder $w) => $w->whereNotIn($column, $value)->orWhereNull($column)),
            'is_null' => $q->whereNull($column),
            'is_not_null' => $q->whereNotNull($column),
        };
    }

    private function applyTag(Builder $q, string $op, mixed $value): void
    {
        match ($op) {
            'eq' => $q->whereHas('tags', fn (Builder $t) => $t->where('tags.slug', $value)),
            'neq' => $q->whereDoesntHave('tags', fn (Builder $t) => $t->where('tags.slug', $value)),
            'contains' => $q->whereHas('tags', fn (Builder $t) => $t->where('tags.slug', 'like', '%'.$this->escapeLike($value).'%')),
            'starts_with' => $q->whereHas('tags', fn (Builder $t) => $t->where('tags.slug', 'like', $this->escapeLike($value).'%')),
            'in' => $q->whereHas('tags', fn (Builder $t) => $t->whereIn('tags.slug', $value)),
            'not_in' => $q->whereDoesntHave('tags', fn (Builder $t) => $t->whereIn('tags.slug', $value)),
            'is_null' => $q->whereDoesntHave('tags'),
            'is_not_null' => $q->whereHas('tags'),
        };
    }

    private function applyConsent(Builder $q, ConsentChannel $channel, string $op, mixed $value): void
    {
        $channelIs = fn (Builder $c) => $c->where('contact_consents.channel', $channel->value);

        match ($op) {
            'eq' => $q->whereHas('consents', fn (Builder $c) => $channelIs($c)->where('contact_consents.status', $value)),
            'neq' => $q->whereDoesntHave('consents', fn (Builder $c) => $channelIs($c)->where('contact_consents.status', $value)),
            'in' => $q->whereHas('consents', fn (Builder $c) => $channelIs($c)->whereIn('contact_consents.status', $value)),
            'not_in' => $q->whereDoesntHave('consents', fn (Builder $c) => $channelIs($c)->whereIn('contact_consents.status', $value)),
            'is_null' => $q->whereDoesntHave('consents', $channelIs),
            'is_not_null' => $q->whereHas('consents', $channelIs),
        };
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
