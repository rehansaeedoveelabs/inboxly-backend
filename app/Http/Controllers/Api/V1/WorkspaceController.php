<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWorkspaceRequest;
use App\Http\Requests\UpdateWorkspaceRequest;
use App\Http\Resources\WorkspaceResource;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WorkspaceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user()->load('workspaceContexts');

        return WorkspaceResource::collection(
            $user->workspaces()->orderBy('workspaces.name')->get()
        );
    }

    public function store(StoreWorkspaceRequest $request, CreateWorkspace $createWorkspace): JsonResponse
    {
        $workspace = $createWorkspace->handle($request->user(), $request->validated('name'));
        $request->user()->load('workspaceContexts');

        return (new WorkspaceResource($workspace))->response()->setStatusCode(201);
    }

    public function show(Request $request, Workspace $workspace): WorkspaceResource
    {
        $this->authorize('view', $workspace);
        $request->user()->load('workspaceContexts');

        return new WorkspaceResource($workspace);
    }

    public function update(UpdateWorkspaceRequest $request, Workspace $workspace): WorkspaceResource
    {
        $workspace->update($request->validated());
        $request->user()->load('workspaceContexts');

        return new WorkspaceResource($workspace);
    }
}
