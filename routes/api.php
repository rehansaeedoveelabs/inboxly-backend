<?php

use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\StoreActivationController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use App\Http\Controllers\Api\V1\WorkspaceInvitationController;
use App\Http\Controllers\Api\V1\WorkspaceMemberController;
use Illuminate\Support\Facades\Route;

/*
| Mounted under /api/v1 (see bootstrap/app.php).
| SPA auth: call GET /sanctum/csrf-cookie first, then use the session cookie.
*/

Route::prefix('auth')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('register', RegisterController::class);
        Route::post('login', [SessionController::class, 'store']);
    });

    Route::middleware('throttle:password-reset')->group(function () {
        Route::post('forgot-password', [PasswordResetController::class, 'forgot']);
        Route::post('reset-password', [PasswordResetController::class, 'reset']);
    });

    // Opened from the email link in a browser; protected by the URL signature.
    Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [SessionController::class, 'destroy']);
        Route::get('me', [ProfileController::class, 'show']);
        Route::put('profile', [ProfileController::class, 'update']);
        Route::put('password', [ProfileController::class, 'updatePassword']);
        Route::post('email/verification-notification', [EmailVerificationController::class, 'send'])
            ->middleware('throttle:6,1');
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('workspaces', [WorkspaceController::class, 'index']);
    Route::post('workspaces', [WorkspaceController::class, 'store']);
    Route::get('workspaces/{workspace}', [WorkspaceController::class, 'show']);
    Route::put('workspaces/{workspace}', [WorkspaceController::class, 'update']);
    Route::get('workspaces/{workspace}/members', [WorkspaceMemberController::class, 'index']);
    Route::post('workspaces/{workspace}/invitations', [WorkspaceInvitationController::class, 'store']);

    Route::get('workspaces/{workspace}/stores', [StoreController::class, 'index']);
    Route::post('workspaces/{workspace}/stores', [StoreController::class, 'store']);
    Route::post('stores/{store}/activate', StoreActivationController::class);
});
