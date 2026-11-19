<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\RegisterUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request, RegisterUser $registerUser): JsonResponse
    {
        $user = $registerUser->handle($request->validated());

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json([
            'data' => (new UserResource($user->load('workspaces', 'workspaceContexts')))->resolve(),
            'meta' => [
                'email_otp_required' => ! $user->hasVerifiedEmail(),
                'next' => 'email_otp',
            ],
        ], 201);
    }
}
