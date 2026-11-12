<?php

namespace App\Http\Controllers\Api\V1\Meta;

use App\Actions\Meta\DisconnectMetaConnection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Meta\DisconnectMetaRequest;
use App\Http\Resources\MetaConnectionResource;
use App\Http\Resources\MetaPageResource;
use App\Models\MetaConnection;
use App\Models\Store;
use Illuminate\Http\JsonResponse;

class MetaConnectionController extends Controller
{
    public function show(Store $store): JsonResponse
    {
        $this->authorize('viewMetaConnection', $store);

        $connection = MetaConnection::query()
            ->where('store_id', $store->getKey())
            ->with(['pages' => fn ($q) => $q->orderByDesc('is_default')->orderBy('name')])
            ->first();

        if ($connection === null) {
            return response()->json([
                'data' => [
                    'connected' => false,
                    'connection' => null,
                    'pages' => [],
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'connected' => true,
                'connection' => (new MetaConnectionResource($connection))->resolve(),
                'pages' => MetaPageResource::collection($connection->pages)->resolve(),
            ],
        ]);
    }

    public function destroy(
        DisconnectMetaRequest $request,
        Store $store,
        DisconnectMetaConnection $disconnect,
    ): JsonResponse {
        $result = $disconnect->handle($store);

        return response()->json([
            'data' => [
                'disconnected' => $result['disconnected'],
            ],
        ]);
    }
}
