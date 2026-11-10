<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\StartContactImport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audience\StartContactImportRequest;
use App\Http\Resources\ContactImportResource;
use App\Models\ContactImport;
use App\Models\Store;
use Illuminate\Http\JsonResponse;

class ContactImportController extends Controller
{
    /** 202: processing is asynchronous; poll GET /contact-imports/{id}. */
    public function store(StartContactImportRequest $request, Store $store, StartContactImport $startImport): JsonResponse
    {
        $import = $startImport->handle(
            $store,
            $request->user(),
            $request->validated('rows'),
            $request->validated('tags', []),
            $request->validated('filename'),
        );

        return (new ContactImportResource($import))->response()->setStatusCode(202);
    }

    public function show(ContactImport $contactImport): ContactImportResource
    {
        $this->authorize('view', $contactImport);

        return new ContactImportResource($contactImport);
    }
}
