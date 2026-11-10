<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\UpdateContact;
use App\Actions\UpsertContact;
use App\Enums\ConsentChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audience\CreateContactRequest;
use App\Http\Requests\Audience\ListContactsRequest;
use App\Http\Requests\Audience\UpdateContactRequest;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ContactController extends Controller
{
    private const DEFAULT_LIMIT = 25;

    /**
     * Keyset ("cursor") pagination over (created_at desc, id desc), backed by the
     * (store_id, created_at, id) index. Pass the returned `meta.next_cursor` as `cursor`.
     */
    public function index(ListContactsRequest $request, Store $store): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $contacts = $store->contacts()
            ->with(['tags', 'consents'])
            ->when($filters['q'] ?? null, function ($query, string $q) {
                // Email prefix match (an exact email is a prefix of itself).
                $query->where('email', 'like', addcslashes(mb_strtolower(trim($q)), '\\%_').'%');
            })
            ->when($filters['tag'] ?? null, fn ($query, string $slug) => $query->whereHas(
                'tags', fn ($t) => $t->where('tags.slug', $slug),
            ))
            ->when($filters['consent_email'] ?? null, fn ($query, string $status) => $query->whereHas(
                'consents', fn ($c) => $c->where('channel', ConsentChannel::Email->value)->where('status', $status),
            ))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate((int) ($filters['limit'] ?? self::DEFAULT_LIMIT))
            ->withQueryString();

        return ContactResource::collection($contacts);
    }

    public function store(CreateContactRequest $request, Store $store, UpsertContact $upsertContact): JsonResponse
    {
        $contact = $upsertContact->handle($store, $request->validated() + ['source' => 'manual']);

        return (new ContactResource($contact->load(['tags', 'consents'])))->response()->setStatusCode(201);
    }

    public function show(Contact $contact): ContactResource
    {
        $this->authorize('view', $contact);

        return new ContactResource($contact->load(['tags', 'consents']));
    }

    public function update(UpdateContactRequest $request, Contact $contact, UpdateContact $updateContact): ContactResource
    {
        return new ContactResource(
            $updateContact->handle($contact, $request->validated())->load(['tags', 'consents'])
        );
    }

    public function destroy(Contact $contact): Response
    {
        $this->authorize('delete', $contact);

        $contact->delete();

        return response()->noContent();
    }
}
