<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\UploadStoreAsset;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssetController extends Controller
{
    private const DEFAULT_LIMIT = 25;

    public function index(Request $request, Store $store): AnonymousResourceCollection
    {
        $this->authorize('manageAudience', $store);

        $data = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:512'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $store->assets()->orderByDesc('created_at')->orderByDesc('id');
        if (! empty($data['q'])) {
            $query->where('name', 'like', '%'.$data['q'].'%');
        }

        $assets = $query
            ->cursorPaginate((int) ($data['limit'] ?? self::DEFAULT_LIMIT))
            ->withQueryString();

        return AssetResource::collection($assets);
    }

    public function store(Request $request, Store $store, UploadStoreAsset $upload): JsonResponse
    {
        $this->authorize('manageAudience', $store);

        $data = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.(int) (UploadStoreAsset::MAX_BYTES / 1024),
                'mimetypes:'.implode(',', UploadStoreAsset::ALLOWED_MIMES),
            ],
            'alt' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $asset = $upload->handle($store, $request->user(), $request->file('file'), $data['alt'] ?? null);

        return (new AssetResource($asset))->response()->setStatusCode(201);
    }

    public function show(Asset $asset): AssetResource
    {
        $this->authorize('view', $asset);

        return new AssetResource($asset);
    }

    public function destroy(Asset $asset): Response
    {
        $this->authorize('delete', $asset);

        if ($asset->path) {
            Storage::disk($asset->disk ?: 'local')->delete($asset->path);
        }
        $asset->delete();

        return response()->noContent();
    }

    public function download(Asset $asset): StreamedResponse|JsonResponse
    {
        $this->authorize('view', $asset);

        if (! $asset->fileExists()) {
            return response()->json(['message' => 'The asset file is no longer available.'], 404);
        }

        return Storage::disk($asset->disk ?: 'local')->download(
            $asset->path,
            $asset->original_filename,
            ['Content-Type' => $asset->mime_type],
        );
    }
}
