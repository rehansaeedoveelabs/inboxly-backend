<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\StartContactExport;
use App\Enums\ConsentStatus;
use App\Enums\ContactExportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audience\ListContactExportsRequest;
use App\Http\Requests\Audience\StartContactExportRequest;
use App\Http\Resources\ContactExportResource;
use App\Models\ContactExport;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactExportController extends Controller
{
    private const DEFAULT_LIMIT = 25;

    public function index(ListContactExportsRequest $request, Store $store): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $exports = $store->contactExports()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate((int) ($filters['limit'] ?? self::DEFAULT_LIMIT))
            ->withQueryString();

        return ContactExportResource::collection($exports);
    }

    public function store(
        StartContactExportRequest $request,
        Store $store,
        StartContactExport $startExport,
    ): JsonResponse {
        $data = $request->validated();
        if (isset($data['consent_email']) && $data['consent_email'] instanceof ConsentStatus) {
            $data['consent_email'] = $data['consent_email']->value;
        }

        $export = $startExport->handle($store, $request->user(), $data);

        return (new ContactExportResource($export))->response()->setStatusCode(202);
    }

    public function show(ContactExport $contactExport): ContactExportResource
    {
        $this->authorize('view', $contactExport);

        return new ContactExportResource($contactExport);
    }

    public function download(ContactExport $contactExport): StreamedResponse|JsonResponse
    {
        $this->authorize('download', $contactExport);

        if ($contactExport->status !== ContactExportStatus::Completed) {
            return response()->json([
                'message' => 'This export is not ready for download.',
            ], 409);
        }

        if ($contactExport->isExpired()) {
            return response()->json([
                'message' => 'This export has expired.',
            ], 410);
        }

        if (! $contactExport->fileExists()) {
            return response()->json([
                'message' => 'The export file is no longer available.',
            ], 404);
        }

        $filename = $contactExport->original_filename ?: 'contacts-export.csv';
        $disk = $contactExport->disk ?: 'local';
        $path = $contactExport->path;

        return Storage::disk($disk)->download($path, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
