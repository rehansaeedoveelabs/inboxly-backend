<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\IssueEmailOtp;
use App\Actions\Auth\VerifyEmailOtp;
use App\Actions\Shopify\StartShopifyInstallOAuth;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyEmailOtpRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailOtpController extends Controller
{
    public function send(Request $request, IssueEmailOtp $issue): JsonResponse
    {
        $issue->handle($request->user());

        return response()->json([
            'message' => 'A verification code has been sent to your email.',
        ]);
    }

    public function verify(
        VerifyEmailOtpRequest $request,
        VerifyEmailOtp $verify,
        StartShopifyInstallOAuth $startInstall,
    ): JsonResponse {
        $user = $verify->handle($request->user(), (string) $request->validated('code'));

        $authorizeUrl = $startInstall->handle($user);

        return response()->json([
            'data' => [
                'user' => (new UserResource($user->load('workspaces', 'workspaceContexts')))->resolve(),
                'shopify_authorize_url' => $authorizeUrl,
            ],
        ]);
    }
}
