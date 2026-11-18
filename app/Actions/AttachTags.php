<?php

namespace App\Actions;

use App\Events\ContactTagAdded;
use App\Models\Contact;
use Illuminate\Support\Facades\DB;

class AttachTags
{
    /**
     * Idempotent. Callers must have validated that every tag belongs to the contact's store.
     * Dispatches ContactTagAdded (after outermost commit) only for newly attached tags.
     *
     * @param  list<string>  $tagIds
     */
    public function handle(Contact $contact, array $tagIds): Contact
    {
        $unique = array_values(array_unique($tagIds));
        if ($unique === []) {
            return $contact->load('tags');
        }

        $existing = DB::table('contact_tag')
            ->where('contact_id', $contact->getKey())
            ->whereIn('tag_id', $unique)
            ->pluck('tag_id')
            ->all();
        $existingLookup = array_flip($existing);
        $newlyAdded = array_values(array_filter(
            $unique,
            static fn (string $tagId) => ! isset($existingLookup[$tagId]),
        ));

        $now = now();

        DB::table('contact_tag')->insertOrIgnore(
            array_map(fn (string $tagId) => [
                'contact_id' => $contact->getKey(),
                'tag_id' => $tagId,
                'created_at' => $now,
            ], $unique),
        );

        if ($newlyAdded !== []) {
            ContactTagAdded::dispatch(
                $contact->getKey(),
                (string) $contact->store_id,
                $newlyAdded,
            );
        }

        return $contact->load('tags');
    }
}
