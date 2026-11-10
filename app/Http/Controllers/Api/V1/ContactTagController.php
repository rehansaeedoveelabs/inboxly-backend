<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AttachTags;
use App\Actions\DetachTags;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audience\AttachTagsRequest;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use App\Models\Tag;

class ContactTagController extends Controller
{
    public function store(AttachTagsRequest $request, Contact $contact, AttachTags $attachTags): ContactResource
    {
        $attachTags->handle($contact, $request->validated('tag_ids'));

        return new ContactResource($contact->load(['tags', 'consents']));
    }

    public function destroy(Contact $contact, Tag $tag, DetachTags $detachTags): ContactResource
    {
        $this->authorize('update', $contact);

        // A tag from another store is indistinguishable from a missing one.
        abort_unless($tag->store_id === $contact->store_id, 404);

        $detachTags->handle($contact, [$tag->getKey()]);

        return new ContactResource($contact->load('consents'));
    }
}
