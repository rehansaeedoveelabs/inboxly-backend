<?php

namespace App\Actions;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;

class AttachTags
{
    /**
     * Idempotent. Callers must have validated that every tag belongs to the contact's store.
     *
     * @param  list<string>  $tagIds
     */
    public function handle(Contact $contact, array $tagIds): Contact
    {
        $now = now();

        DB::table('contact_tag')->insertOrIgnore(
            array_map(fn (string $tagId) => [
                'contact_id' => $contact->getKey(),
                'tag_id' => $tagId,
                'created_at' => $now,
            ], array_values(array_unique($tagIds))),
        );

        return $contact->load('tags');
    }
}
