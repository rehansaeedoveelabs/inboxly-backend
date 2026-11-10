<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\SetActiveStore;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContextResource;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreActivationController extends Controller
{
    public function __invoke(Request $request, Store $store, SetActiveStore $setActiveStore): JsonResponse
    {
        $this->authorize('activate', $store);

        $context = $setActiveStore->handle($request->user(), $store->workspace, $store);

        return (new ContextResource($context->load('activeStore')))
            ->response()
            ->setStatusCode(200);
    }
}
