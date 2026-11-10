<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateStoreRequest;
use App\Http\Resources\StoreResource;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StoreController extends Controller
{
    public function index(Workspace $workspace): AnonymousResourceCollection
    {
        $this->authorize('viewStores', $workspace);

        return StoreResource::collection($workspace->stores()->orderBy('name')->get());
    }

    public function store(CreateStoreRequest $request, Workspace $workspace, CreateStore $createStore): JsonResponse
    {
        $store = $createStore->handle($workspace, $request->validated());

        return (new StoreResource($store))->response()->setStatusCode(201);
    }
}
