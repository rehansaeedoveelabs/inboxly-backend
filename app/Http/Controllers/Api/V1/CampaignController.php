<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateCampaign;
use App\Actions\DuplicateCampaign;
use App\Actions\ScheduleCampaign;
use App\Actions\UnscheduleCampaign;
use App\Actions\UpdateCampaign;
use App\Enums\CampaignAudienceType;
use App\Enums\CampaignChannel;
use App\Enums\CampaignStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Campaigns\CreateCampaignRequest;
use App\Http\Requests\Campaigns\ListCampaignsRequest;
use App\Http\Requests\Campaigns\PreviewCampaignAudienceRequest;
use App\Http\Requests\Campaigns\ScheduleCampaignRequest;
use App\Http\Requests\Campaigns\UpdateCampaignRequest;
use App\Http\Resources\CampaignResource;
use App\Models\Campaign;
use App\Models\Segment;
use App\Models\Store;
use App\Support\Campaigns\CampaignAudienceCounter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CampaignController extends Controller
{
    private const DEFAULT_LIMIT = 25;

    public function index(ListCampaignsRequest $request, Store $store): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $query = $store->campaigns()
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! empty($filters['q'])) {
            $query->where('name', 'like', '%'.$filters['q'].'%');
        }

        if (! empty($filters['status'])) {
            $status = $filters['status'] instanceof CampaignStatus
                ? $filters['status']
                : CampaignStatus::from($filters['status']);
            $query->where('status', $status);
        }

        if (! empty($filters['channel'])) {
            $channel = $filters['channel'] instanceof CampaignChannel
                ? $filters['channel']
                : CampaignChannel::from($filters['channel']);
            $query->where('channel', $channel);
        }

        $campaigns = $query
            ->cursorPaginate((int) ($filters['limit'] ?? self::DEFAULT_LIMIT))
            ->withQueryString();

        return CampaignResource::collection($campaigns);
    }

    public function summary(Store $store): JsonResponse
    {
        $this->authorize('manageAudience', $store);

        $base = $store->campaigns();

        return response()->json([
            'total' => (clone $base)->count(),
            'draft' => (clone $base)->where('status', CampaignStatus::Draft)->count(),
            'scheduled' => (clone $base)->where('status', CampaignStatus::Scheduled)->count(),
        ]);
    }

    public function audiencePreview(
        PreviewCampaignAudienceRequest $request,
        Store $store,
        CampaignAudienceCounter $counter,
    ): JsonResponse {
        $data = $request->validated();

        $channel = $data['channel'] instanceof CampaignChannel
            ? $data['channel']
            : CampaignChannel::from($data['channel']);
        $audienceType = $data['audience_type'] instanceof CampaignAudienceType
            ? $data['audience_type']
            : CampaignAudienceType::from($data['audience_type']);

        $segment = null;
        if ($audienceType === CampaignAudienceType::Segment) {
            $segment = Segment::query()
                ->where('store_id', $store->getKey())
                ->whereKey($data['segment_id'])
                ->firstOrFail();
        }

        return response()->json([
            'count' => $counter->count($store, $channel, $audienceType, $segment),
        ]);
    }

    public function store(
        CreateCampaignRequest $request,
        Store $store,
        CreateCampaign $createCampaign,
    ): JsonResponse {
        $campaign = $createCampaign->handle($store, $request->user(), $request->validated());

        return (new CampaignResource($campaign))->response()->setStatusCode(201);
    }

    public function show(Campaign $campaign): CampaignResource
    {
        $this->authorize('view', $campaign);

        return new CampaignResource($campaign);
    }

    public function update(
        UpdateCampaignRequest $request,
        Campaign $campaign,
        UpdateCampaign $updateCampaign,
    ): CampaignResource {
        return new CampaignResource($updateCampaign->handle($campaign, $request->validated()));
    }

    public function destroy(Campaign $campaign): Response
    {
        $this->authorize('delete', $campaign);

        if ($campaign->isScheduled()) {
            throw new ConflictHttpException('Scheduled campaigns cannot be deleted. Unschedule first.');
        }

        $campaign->delete();

        return response()->noContent();
    }

    public function schedule(
        ScheduleCampaignRequest $request,
        Campaign $campaign,
        ScheduleCampaign $scheduleCampaign,
    ): CampaignResource {
        return new CampaignResource($scheduleCampaign->handle($campaign, $request->validated()));
    }

    public function unschedule(Campaign $campaign, UnscheduleCampaign $unscheduleCampaign): CampaignResource
    {
        $this->authorize('unschedule', $campaign);

        return new CampaignResource($unscheduleCampaign->handle($campaign));
    }

    public function duplicate(Campaign $campaign, DuplicateCampaign $duplicateCampaign): JsonResponse
    {
        $this->authorize('duplicate', $campaign);

        $copy = $duplicateCampaign->handle($campaign, request()->user());

        return (new CampaignResource($copy))->response()->setStatusCode(201);
    }
}
