<?php

namespace App\Actions;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Models\Contact;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

class UpsertContact
{
    private const FIELDS = ['email', 'phone', 'first_name', 'last_name', 'locale', 'timezone', 'source', 'metadata'];

    /**
     * Finds the store's contact by email (or phone when there is no email) and updates it,
     * otherwise creates it together with a "nonsubscribed" consent row per channel.
     * A soft-deleted match is restored so the unique (store, email|phone) index is never violated.
     *
     * Existing contacts are only changed by keys present in $data with a non-null value, so
     * partial payloads (e.g. imports) never blank out existing data.
     *
     * Check `$contact->wasRecentlyCreated` to tell create from update.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(Store $store, array $data): Contact
    {
        $email = Contact::normalizeEmail($data['email'] ?? null);
        $phone = Contact::normalizePhone($data['phone'] ?? null);

        return DB::transaction(function () use ($store, $data, $email, $phone) {
            $contact = $this->find($store, $email, $phone);

            $attributes = array_filter(
                array_intersect_key($data, array_flip(self::FIELDS)),
                fn ($value) => $value !== null,
            );

            if ($contact === null) {
                $contact = $store->contacts()->create($attributes);

                foreach (ConsentChannel::cases() as $channel) {
                    $contact->consents()->create([
                        'channel' => $channel,
                        'status' => ConsentStatus::Nonsubscribed,
                    ]);
                }

                return $contact;
            }

            // Preserve existing consents and consent_events. Restoration only clears
            // deleted_at and sets the review gate so marketing stays blocked until review.
            if ($contact->trashed()) {
                $contact->restore();
                $contact->consent_review_required_at = now();
                $contact->wasRestored = true;
            }

            $contact->fill($attributes)->save();

            return $contact;
        });
    }

    private function find(Store $store, ?string $email, ?string $phone): ?Contact
    {
        $query = Contact::withTrashed()->where('store_id', $store->getKey())->lockForUpdate();

        if ($email !== null) {
            return $query->where('email', $email)->first();
        }

        return $phone !== null ? $query->where('phone', $phone)->first() : null;
    }
}
