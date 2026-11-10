<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkspaceMemberResource;
use App\Models\Workspace;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WorkspaceMemberController extends Controller
{
    public function index(Workspace $workspace): AnonymousResourceCollection
    {
        $this->authorize('viewMembers', $workspace);

        return WorkspaceMemberResource::collection(
            $workspace->members()->with('user')->orderBy('created_at')->get()
        );
    }
}
