<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateSegment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audience\CreateSegmentRequest;
use App\Http\Requests\Audience\UpdateSegmentRequest;
use App\Http\Resources\ContactResource;
use App\Http\Resources\SegmentResource;
use App\Models\Segment;
use App\Models\Store;
use App\Support\Segments\SegmentQueryBuilder;
use App\Support\Segments\SegmentRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SegmentController extends Controller
{
    private const PREVIEW_SAMPLE_SIZE = 10;

    public function index(Store $store): AnonymousResourceCollection
    {
        $this->authorize('manageAudience', $store);

        return SegmentResource::collection($store->segments()->orderBy('name')->orderBy('id')->get());
    }

    public function store(CreateSegmentRequest $request, Store $store, CreateSegment $createSegment): JsonResponse
    {
        return (new SegmentResource($createSegment->handle($store, $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function show(Segment $segment): SegmentResource
    {
        $this->authorize('view', $segment);

        return new SegmentResource($segment);
    }

    public function update(UpdateSegmentRequest $request, Segment $segment): SegmentResource
    {
        $data = $request->validated();

        if (isset($data['rules'])) {
            $data['rules'] = SegmentRules::normalize($data['rules']);
        }

        $segment->update($data);

        return new SegmentResource($segment);
    }

    public function destroy(Segment $segment): Response
    {
        $this->authorize('delete', $segment);

        $segment->delete();

        return response()->noContent();
    }

    /** Live count + a small sample; membership is computed from the rules, never stored. */
    public function preview(Request $request, Segment $segment, SegmentQueryBuilder $builder): JsonResponse
    {
        $this->authorize('view', $segment);

        $count = $builder->forSegment($segment)->count();

        $sample = $builder->forSegment($segment)
            ->with(['tags', 'consents'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(self::PREVIEW_SAMPLE_SIZE)
            ->get();

        return response()->json([
            'count' => $count,
            'sample' => ContactResource::collection($sample)->resolve($request),
        ]);
    }
}
