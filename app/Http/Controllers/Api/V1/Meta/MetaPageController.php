<?php

namespace App\Http\Controllers\Api\V1\Meta;

use App\Actions\Meta\DiscoverMetaPages;
use App\Actions\Meta\RemoveMetaPage;
use App\Actions\Meta\SelectMetaPages;
use App\Actions\Meta\SetDefaultMetaPage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Meta\SelectMetaPagesRequest;
use App\Http\Resources\MetaPageResource;
use App\Models\MetaPage;
use App\Models\Store;
use Illuminate\Http\JsonResponse;

class MetaPageController extends Controller
{
    public function discover(Store $store, DiscoverMetaPages $discover): JsonResponse
    {
        $this->authorize('manageMetaPages', $store);

        $pages = $discover->handle($store);

        return response()->json([
            'data' => [
                'pages' => $pages,
            ],
        ]);
    }

    public function select(
        SelectMetaPagesRequest $request,
        Store $store,
        SelectMetaPages $select,
    ): JsonResponse {
        $pages = $select->handle(
            $store,
            $request->validated('page_ids'),
            $request->validated('default_page_id'),
        );

        return response()->json([
            'data' => MetaPageResource::collection($pages)->resolve(),
        ]);
    }

    public function setDefault(
        Store $store,
        MetaPage $page,
        SetDefaultMetaPage $setDefault,
    ): JsonResponse {
        $this->authorize('manageMetaPages', $store);

        $updated = $setDefault->handle($store, $page);

        return response()->json([
            'data' => (new MetaPageResource($updated))->resolve(),
        ]);
    }

    public function destroy(
        Store $store,
        MetaPage $page,
        RemoveMetaPage $remove,
    ): JsonResponse {
        $this->authorize('manageMetaPages', $store);

        $remove->handle($store, $page);

        return response()->json([
            'data' => ['removed' => true],
        ]);
    }
}
