<?php

namespace App\Http\Controllers\Api\V1\Meta;

use App\Actions\Meta\CreateMetaPublications;
use App\Actions\Meta\ListEligibleMetaPages;
use App\Actions\Meta\ReconcileMetaPublication;
use App\Actions\Meta\ResolveMetaPublication;
use App\Actions\Meta\RetryMetaPublication;
use App\Actions\Meta\UpdateCampaignSocialContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Meta\CreateMetaPublicationsRequest;
use App\Http\Requests\Meta\ResolveMetaPublicationRequest;
use App\Http\Requests\Meta\UpdateCampaignSocialContentRequest;
use App\Http\Resources\CampaignMetaPublicationResource;
use App\Http\Resources\CampaignResource;
use App\Models\Campaign;
use App\Models\CampaignMetaPublication;
use Illuminate\Http\JsonResponse;

class CampaignMetaPublicationController extends Controller
{
    public function eligiblePages(Campaign $campaign, ListEligibleMetaPages $list): JsonResponse
    {
        $this->authorize('publishMeta', $campaign);

        return response()->json([
            'data' => $list->handle($campaign->store),
        ]);
    }

    public function updateSocialContent(
        UpdateCampaignSocialContentRequest $request,
        Campaign $campaign,
        UpdateCampaignSocialContent $update,
    ): CampaignResource {
        $updated = $update->handle($campaign, $request->validated());

        return new CampaignResource($updated);
    }

    public function index(Campaign $campaign): JsonResponse
    {
        $this->authorize('view', $campaign);

        $rows = CampaignMetaPublication::query()
            ->where('campaign_id', $campaign->getKey())
            ->where('store_id', $campaign->store_id)
            ->with('metaPage')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => CampaignMetaPublicationResource::collection($rows)->resolve(),
        ]);
    }

    public function store(
        CreateMetaPublicationsRequest $request,
        Campaign $campaign,
        CreateMetaPublications $create,
    ): JsonResponse {
        $rows = $create->handle(
            $campaign,
            $request->validated('meta_page_ids'),
            (bool) $request->boolean('confirm_republish'),
        );

        $rows = CampaignMetaPublication::query()
            ->whereIn('id', collect($rows)->map->getKey()->all())
            ->with('metaPage')
            ->get();

        return response()->json([
            'data' => CampaignMetaPublicationResource::collection($rows)->resolve(),
        ], 202);
    }

    public function retry(
        Campaign $campaign,
        CampaignMetaPublication $publication,
        RetryMetaPublication $retry,
    ): JsonResponse {
        $this->authorize('publishMeta', $campaign);
        $this->assertPublicationBelongs($campaign, $publication);

        $updated = $retry->handle($publication)->load('metaPage');

        return response()->json([
            'data' => (new CampaignMetaPublicationResource($updated))->resolve(),
        ]);
    }

    public function reconcile(
        Campaign $campaign,
        CampaignMetaPublication $publication,
        ReconcileMetaPublication $reconcile,
    ): JsonResponse {
        $this->authorize('publishMeta', $campaign);
        $this->assertPublicationBelongs($campaign, $publication);

        $updated = $reconcile->handle($publication)->load('metaPage');

        return response()->json([
            'data' => (new CampaignMetaPublicationResource($updated))->resolve(),
        ]);
    }

    public function resolve(
        ResolveMetaPublicationRequest $request,
        Campaign $campaign,
        CampaignMetaPublication $publication,
        ResolveMetaPublication $resolve,
    ): JsonResponse {
        $this->assertPublicationBelongs($campaign, $publication);

        $updated = $resolve->handle($publication, $request->validated())->load('metaPage');

        return response()->json([
            'data' => (new CampaignMetaPublicationResource($updated))->resolve(),
        ]);
    }

    private function assertPublicationBelongs(Campaign $campaign, CampaignMetaPublication $publication): void
    {
        abort_unless(
            $publication->campaign_id === $campaign->getKey()
            && $publication->store_id === $campaign->store_id,
            404,
        );
    }
}
