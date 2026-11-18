<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Automations\ActivateAutomation;
use App\Actions\Automations\ArchiveAutomation;
use App\Actions\Automations\CreateAutomation;
use App\Actions\Automations\PauseAutomation;
use App\Actions\Automations\UpdateAutomation;
use App\Enums\AutomationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Automations\CreateAutomationRequest;
use App\Http\Requests\Automations\ListAutomationsRequest;
use App\Http\Requests\Automations\UpdateAutomationRequest;
use App\Http\Resources\AutomationEnrollmentResource;
use App\Http\Resources\AutomationResource;
use App\Models\Automation;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AutomationController extends Controller
{
    private const DEFAULT_LIMIT = 25;

    public function index(ListAutomationsRequest $request, Store $store): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $query = $store->automations()
            ->with('steps')
            ->withCount('enrollments')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! empty($filters['q'])) {
            $query->where('name', 'like', '%'.$filters['q'].'%');
        }

        if (! empty($filters['status'])) {
            $status = $filters['status'] instanceof AutomationStatus
                ? $filters['status']
                : AutomationStatus::from($filters['status']);
            $query->where('status', $status);
        }

        $automations = $query
            ->cursorPaginate((int) ($filters['limit'] ?? self::DEFAULT_LIMIT))
            ->withQueryString();

        return AutomationResource::collection($automations);
    }

    public function summary(Store $store): JsonResponse
    {
        $this->authorize('manageAudience', $store);

        $base = $store->automations();

        return response()->json([
            'total' => (clone $base)->count(),
            'draft' => (clone $base)->where('status', AutomationStatus::Draft)->count(),
            'active' => (clone $base)->where('status', AutomationStatus::Active)->count(),
            'paused' => (clone $base)->where('status', AutomationStatus::Paused)->count(),
            'archived' => (clone $base)->where('status', AutomationStatus::Archived)->count(),
        ]);
    }

    public function store(
        CreateAutomationRequest $request,
        Store $store,
        CreateAutomation $create,
    ): JsonResponse {
        $automation = $create->handle($store, $request->user(), $request->validated());

        return (new AutomationResource($automation))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Automation $automation): AutomationResource
    {
        $this->authorize('view', $automation);

        $automation->load('steps')->loadCount('enrollments');

        return new AutomationResource($automation);
    }

    public function update(
        UpdateAutomationRequest $request,
        Automation $automation,
        UpdateAutomation $update,
    ): AutomationResource {
        $automation = $update->handle($automation, $request->validated());

        return new AutomationResource($automation->loadCount('enrollments'));
    }

    public function activate(Automation $automation, ActivateAutomation $activate): AutomationResource
    {
        $this->authorize('activate', $automation);

        return new AutomationResource($activate->handle($automation)->loadCount('enrollments'));
    }

    public function pause(Automation $automation, PauseAutomation $pause): AutomationResource
    {
        $this->authorize('pause', $automation);

        return new AutomationResource($pause->handle($automation)->loadCount('enrollments'));
    }

    public function archive(Automation $automation, ArchiveAutomation $archive): AutomationResource
    {
        $this->authorize('archive', $automation);

        return new AutomationResource($archive->handle($automation)->loadCount('enrollments'));
    }

    public function enrollments(Request $request, Automation $automation): AnonymousResourceCollection
    {
        $this->authorize('view', $automation);

        $limit = max(1, min(100, (int) $request->query('limit', self::DEFAULT_LIMIT)));

        $enrollments = $automation->enrollments()
            ->with(['contact', 'stepRuns.step'])
            ->orderByDesc('enrolled_at')
            ->orderByDesc('id')
            ->cursorPaginate($limit)
            ->withQueryString();

        return AutomationEnrollmentResource::collection($enrollments);
    }
}
