<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ReviewContactConsents;
use App\Actions\UpdateContactConsents;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audience\UpdateConsentsRequest;
use App\Http\Resources\ContactResource;
use App\Models\Contact;

class ContactConsentController extends Controller
{
    public function update(UpdateConsentsRequest $request, Contact $contact, UpdateContactConsents $updateConsents): ContactResource
    {
        $updateConsents->handle($contact, $request->validated('consents'));

        return new ContactResource($contact->load(['tags', 'consents']));
    }

    /**
     * Clears the post-restore review gate without changing consent statuses or events.
     */
    public function review(Contact $contact, ReviewContactConsents $reviewConsents): ContactResource
    {
        $this->authorize('reviewConsents', $contact);

        return new ContactResource(
            $reviewConsents->handle($contact)->load(['tags', 'consents'])
        );
    }
}
