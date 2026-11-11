<?php

use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\ContactConsentController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\ContactExportController;
use App\Http\Controllers\Api\V1\ContactImportController;
use App\Http\Controllers\Api\V1\ContactTagController;
use App\Http\Controllers\Api\V1\EmailTemplateController;
use App\Http\Controllers\Api\V1\SegmentController;
use App\Http\Controllers\Api\V1\Shopify\ShopifyConnectionController;
use App\Http\Controllers\Api\V1\Shopify\ShopifyOAuthController;
use App\Http\Controllers\Api\V1\Shopify\ShopifyWebhookController;
use App\Http\Controllers\Api\V1\StoreActivationController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\TagController;
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

/*
| Shopify OAuth callback — public (Shopify redirects the merchant browser here).
| Full path: /api/v1/shopify/oauth/callback
*/
Route::get('shopify/oauth/callback', [ShopifyOAuthController::class, 'callback'])
    ->middleware('throttle:60,1')
    ->name('shopify.oauth.callback');

/*
| Shopify webhooks — public (verified via X-Shopify-Hmac-Sha256).
| Full path: /api/v1/shopify/webhooks
*/
Route::post('shopify/webhooks', ShopifyWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('shopify.webhooks');

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

    Route::get('stores/{store}/shopify/oauth/start', [ShopifyOAuthController::class, 'start'])
        ->middleware('throttle:10,1')
        ->name('shopify.oauth.start');

    Route::delete('stores/{store}/shopify/connection', [ShopifyConnectionController::class, 'destroy'])
        ->middleware('throttle:10,1')
        ->name('shopify.connection.destroy');

    // Audience (Phase 2). Every handler authorises against the owning store's workspace.
    Route::get('stores/{store}/contacts', [ContactController::class, 'index']);
    Route::post('stores/{store}/contacts', [ContactController::class, 'store']);
    Route::get('contacts/{contact}', [ContactController::class, 'show']);
    Route::put('contacts/{contact}', [ContactController::class, 'update']);
    Route::delete('contacts/{contact}', [ContactController::class, 'destroy']);
    Route::post('contacts/{contact}/restore', [ContactController::class, 'restore'])->withTrashed();
    Route::put('contacts/{contact}/consents', [ContactConsentController::class, 'update']);
    Route::post('contacts/{contact}/consent-review', [ContactConsentController::class, 'review']);
    Route::post('contacts/{contact}/tags', [ContactTagController::class, 'store']);
    Route::delete('contacts/{contact}/tags/{tag}', [ContactTagController::class, 'destroy']);

    Route::get('stores/{store}/tags', [TagController::class, 'index']);
    Route::post('stores/{store}/tags', [TagController::class, 'store']);
    Route::put('tags/{tag}', [TagController::class, 'update']);
    Route::delete('tags/{tag}', [TagController::class, 'destroy']);

    Route::get('stores/{store}/segments', [SegmentController::class, 'index']);
    Route::post('stores/{store}/segments', [SegmentController::class, 'store']);
    Route::get('segments/{segment}', [SegmentController::class, 'show']);
    Route::put('segments/{segment}', [SegmentController::class, 'update']);
    Route::delete('segments/{segment}', [SegmentController::class, 'destroy']);
    Route::post('segments/{segment}/preview', [SegmentController::class, 'preview']);

    Route::post('stores/{store}/contact-imports', [ContactImportController::class, 'store']);
    Route::get('contact-imports/{contactImport}', [ContactImportController::class, 'show']);

    Route::get('stores/{store}/contact-exports', [ContactExportController::class, 'index']);
    Route::post('stores/{store}/contact-exports', [ContactExportController::class, 'store'])
        ->middleware('throttle:contact-exports');
    Route::get('contact-exports/{contactExport}', [ContactExportController::class, 'show']);
    Route::get('contact-exports/{contactExport}/download', [ContactExportController::class, 'download'])
        ->middleware('throttle:contact-exports');

    Route::get('stores/{store}/campaigns/summary', [CampaignController::class, 'summary']);
    Route::post('stores/{store}/campaigns/audience-preview', [CampaignController::class, 'audiencePreview']);
    Route::get('stores/{store}/campaigns', [CampaignController::class, 'index']);
    Route::post('stores/{store}/campaigns', [CampaignController::class, 'store']);
    Route::get('campaigns/{campaign}', [CampaignController::class, 'show']);
    Route::put('campaigns/{campaign}', [CampaignController::class, 'update']);
    Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy']);
    Route::post('campaigns/{campaign}/schedule', [CampaignController::class, 'schedule']);
    Route::post('campaigns/{campaign}/unschedule', [CampaignController::class, 'unschedule']);
    Route::post('campaigns/{campaign}/duplicate', [CampaignController::class, 'duplicate']);
    Route::post('campaigns/{campaign}/apply-template', [CampaignController::class, 'applyTemplate']);
    Route::post('campaigns/{campaign}/send', [CampaignController::class, 'send']);
    Route::post('campaigns/{campaign}/test-send', [CampaignController::class, 'testSend']);

    Route::get('stores/{store}/assets', [AssetController::class, 'index']);
    Route::post('stores/{store}/assets', [AssetController::class, 'store']);
    Route::get('assets/{asset}', [AssetController::class, 'show']);
    Route::delete('assets/{asset}', [AssetController::class, 'destroy']);
    Route::get('assets/{asset}/download', [AssetController::class, 'download']);

    Route::get('stores/{store}/email-templates', [EmailTemplateController::class, 'index']);
    Route::post('stores/{store}/email-templates', [EmailTemplateController::class, 'store']);
    Route::post('stores/{store}/email-templates/preview', [EmailTemplateController::class, 'preview']);
    Route::get('email-templates/{emailTemplate}', [EmailTemplateController::class, 'show']);
    Route::put('email-templates/{emailTemplate}', [EmailTemplateController::class, 'update']);
    Route::delete('email-templates/{emailTemplate}', [EmailTemplateController::class, 'destroy']);
    Route::post('email-templates/{emailTemplate}/duplicate', [EmailTemplateController::class, 'duplicate']);
});
