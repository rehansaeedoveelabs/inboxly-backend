<?php

namespace App\Actions;

use App\Models\Contact;

/**
 * Clears the post-restore consent review gate. Does not change channel statuses
 * and does not append consent events.
 */
class ReviewContactConsents
{
    public function handle(Contact $contact): Contact
    {
        $contact->forceFill(['consent_review_required_at' => null])->save();

        return $contact;
    }
}
