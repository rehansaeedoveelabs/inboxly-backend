<?php

namespace App\Http\Controllers\Api\V1\Meta;

use App\Actions\Meta\HandleMetaOAuthCallback;
use App\Actions\Meta\StartMetaOAuth;
use App\Exceptions\MetaOAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Meta\StartMetaOAuthRequest;
use App\Models\Store;
use App\Support\FrontendUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class MetaOAuthController extends Controller
{
    /**
     * Authenticated SPA entry: validates the store, persists OAuth state, redirects to Meta (or fake).
     */
    public function start(
        StartMetaOAuthRequest $request,
        Store $store,
        StartMetaOAuth $startMetaOAuth,
    ): RedirectResponse {
        $url = $startMetaOAuth->handle($request->user(), $store);

        return redirect()->away($url);
    }

    /**
     * Public Meta redirect target — no browser session required.
     * Full path: /api/v1/meta/oauth/callback
     */
    public function callback(Request $request, HandleMetaOAuthCallback $handler): RedirectResponse
    {
        try {
            $result = $handler->handle($request->query());

            return $this->redirectToFrontend('connected', [
                'store_id' => $result['store']->getKey(),
            ]);
        } catch (ValidationException) {
            return $this->redirectToFrontend('error', [
                'reason' => 'validation',
            ]);
        } catch (MetaOAuthException $e) {
            Log::notice('Meta OAuth callback rejected.', [
                'reason' => $e->reasonCode,
            ]);

            return $this->redirectToFrontend('error', [
                'reason' => $e->reasonCode,
            ]);
        }
    }

    /**
     * @param  array<string, string>  $query
     */
    private function redirectToFrontend(string $status, array $query = []): RedirectResponse
    {
        $params = array_merge(['meta' => $status], $query);

        return redirect()->away(FrontendUrl::to('/settings/meta', $params));
    }
}
