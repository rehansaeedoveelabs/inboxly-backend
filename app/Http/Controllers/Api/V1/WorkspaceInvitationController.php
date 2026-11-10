<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\InviteMember;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateInvitationRequest;
use App\Http\Resources\InvitationResource;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;

class WorkspaceInvitationController extends Controller
{
    public function store(CreateInvitationRequest $request, Workspace $workspace, InviteMember $inviteMember): JsonResponse
    {
        $invitation = $inviteMember->handle(
            $workspace,
            $request->user(),
            $request->validated('email'),
            WorkspaceRole::from($request->validated('role')),
        );

        return (new InvitationResource($invitation))->response()->setStatusCode(201);
    }
}
