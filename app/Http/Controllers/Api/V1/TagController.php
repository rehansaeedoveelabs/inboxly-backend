<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateTag;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audience\CreateTagRequest;
use App\Http\Requests\Audience\UpdateTagRequest;
use App\Http\Resources\TagResource;
use App\Models\Store;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class TagController extends Controller
{
    public function index(Store $store): AnonymousResourceCollection
    {
        $this->authorize('manageAudience', $store);

        return TagResource::collection($store->tags()->orderBy('name')->orderBy('id')->get());
    }

    public function store(CreateTagRequest $request, Store $store, CreateTag $createTag): JsonResponse
    {
        return (new TagResource($createTag->handle($store, $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function update(UpdateTagRequest $request, Tag $tag): TagResource
    {
        $tag->update($request->validated());

        return new TagResource($tag);
    }

    public function destroy(Tag $tag): Response
    {
        $this->authorize('delete', $tag);

        $tag->delete();

        return response()->noContent();
    }
}
