<?php

namespace App\Support\Audience;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Models\Contact;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared contact list/export filters. Must stay behaviour-compatible with the
 * store contact index (email prefix, tag slug, email consent status).
 */
class ContactListQuery
{
    /**
     * @param  Builder<Contact>  $query
     * @param  array{q?: string|null, tag?: string|null, consent_email?: string|ConsentStatus|null}  $filters
     * @return Builder<Contact>
     */
    public function apply(Builder $query, array $filters): Builder
    {
        $q = $filters['q'] ?? null;
        // Preserve legacy list behaviour: any non-empty string (including whitespace-only)
        // applies the email prefix filter after trim (whitespace-only becomes LIKE '%').
        if (is_string($q) && $q !== '') {
            // Email prefix match (an exact email is a prefix of itself).
            $query->where(
                'email',
                'like',
                addcslashes(mb_strtolower(trim($q)), '\\%_').'%'
            );
        }

        $tag = $filters['tag'] ?? null;
        if (is_string($tag) && $tag !== '') {
            $query->whereHas(
                'tags',
                fn (Builder $t) => $t->where('tags.slug', $tag),
            );
        }

        $consentEmail = $filters['consent_email'] ?? null;
        if ($consentEmail instanceof ConsentStatus) {
            $consentEmail = $consentEmail->value;
        }
        if (is_string($consentEmail) && $consentEmail !== '') {
            $query->whereHas(
                'consents',
                fn (Builder $c) => $c
                    ->where('channel', ConsentChannel::Email->value)
                    ->where('status', $consentEmail),
            );
        }

        return $query;
    }

    /**
     * Active (non-deleted) contacts for a store with shared filters applied.
     *
     * @param  array{q?: string|null, tag?: string|null, consent_email?: string|ConsentStatus|null}  $filters
     * @return Builder<Contact>
     */
    public function forStore(Store $store, array $filters = []): Builder
    {
        return $this->apply(
            Contact::query()->where('store_id', $store->getKey()),
            $filters,
        );
    }
}
