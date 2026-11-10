<?php

namespace App\Actions;

use App\Models\Contact;

class UpdateContact
{
    /**
     * Unlike UpsertContact, explicit nulls clear the field (PUT semantics).
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(Contact $contact, array $data): Contact
    {
        $contact->fill(array_intersect_key($data, array_flip([
            'email', 'phone', 'first_name', 'last_name', 'locale', 'timezone', 'source', 'metadata',
        ])))->save();

        return $contact;
    }
}
