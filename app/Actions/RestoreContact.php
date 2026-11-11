<?php

namespace App\Actions;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;

class RestoreContact
{
    /**
     * Restores a soft-deleted contact without changing consent statuses or appending events.
     * Sets consent_review_required_at so marketing stays blocked until an explicit review.
     */
    public function handle(Contact $contact): Contact
    {
        return DB::transaction(function () use ($contact) {
            $locked = Contact::withTrashed()
                ->whereKey($contact->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->trashed()) {
                return $locked;
            }

            $locked->restore();
            $locked->forceFill(['consent_review_required_at' => now()])->save();
            $locked->wasRestored = true;

            return $locked;
        });
    }
}
