<?php

namespace App\Actions;

use App\Models\Contact;

class DetachTags
{
    /** @param  list<string>  $tagIds */
    public function handle(Contact $contact, array $tagIds): Contact
    {
        $contact->tags()->detach($tagIds);

        return $contact->load('tags');
    }
}
