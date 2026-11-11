<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RestoreContact;
use App\Actions\UpdateContact;
use App\Actions\UpsertContact;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audience\CreateContactRequest;
use App\Http\Requests\Audience\ListContactsRequest;
use App\Http\Requests\Audience\UpdateContactRequest;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use App\Models\Store;
use App\Support\Audience\ContactListQuery;
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
    public function index(
        ListContactsRequest $request,
        Store $store,
        ContactListQuery $contactListQuery,
    ): AnonymousResourceCollection {
        $filters = $request->validated();

        $contacts = $contactListQuery
            ->forStore($store, $filters)
            ->with(['tags', 'consents'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate((int) ($filters['limit'] ?? self::DEFAULT_LIMIT))
            ->withQueryString();

        return ContactResource::collection($contacts);
    }

    public function store(CreateContactRequest $request, Store $store, UpsertContact $upsertContact): JsonResponse
    {
        $data = $request->validated();
        $restore = (bool) ($data['restore'] ?? false);
        unset($data['restore']);

        if (! $restore) {
            $trashed = $this->findTrashedMatch(
                $store,
                $data['email'] ?? null,
                $data['phone'] ?? null,
            );

            if ($trashed !== null) {
                return response()->json([
                    'error' => 'contact_deleted',
                    'message' => 'A deleted contact with this email or phone already exists. Restore it to continue.',
                    'contact' => (new ContactResource($trashed->load(['tags', 'consents'])))->resolve(),
                ], 409);
            }
        }

        $contact = $upsertContact->handle($store, $data + ['source' => 'manual']);

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

    public function restore(Contact $contact, RestoreContact $restoreContact): JsonResponse
    {
        $this->authorize('restore', $contact);

        $restored = $restoreContact->handle($contact);

        return (new ContactResource($restored->load(['tags', 'consents'])))->response()->setStatusCode(200);
    }

    private function findTrashedMatch(Store $store, ?string $email, ?string $phone): ?Contact
    {
        $email = Contact::normalizeEmail($email);
        $phone = Contact::normalizePhone($phone);

        $base = Contact::onlyTrashed()->where('store_id', $store->getKey());

        if ($email !== null) {
            $match = (clone $base)->where('email', $email)->first();
            if ($match !== null) {
                return $match;
            }
        }

        if ($phone !== null) {
            return (clone $base)->where('phone', $phone)->first();
        }

        return null;
    }
}
