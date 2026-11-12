<?php

namespace App\Actions\Meta;

use App\Models\MetaOauthState;
use App\Models\Store;
use App\Models\User;
use App\Support\Meta\Contracts\MetaProvider;
use Illuminate\Validation\ValidationException;

class StartMetaOAuth
{
    public function __construct(private readonly MetaProvider $provider) {}

    /**
     * Create a single-use hashed OAuth state and return the Meta authorize URL.
     * Workspace is taken only from the Store — never from the client.
     */
    public function handle(User $user, Store $store): string
    {
        if ($store->is_demo) {
            throw ValidationException::withMessages([
                'store' => ['Demo stores cannot connect to Meta.'],
            ]);
        }

        $workspace = $store->workspace;
        if ($workspace === null || ! $workspace->hasMember($user)) {
            throw ValidationException::withMessages([
                'store' => ['You are not a member of this store\'s workspace.'],
            ]);
        }

        $hasContext = $user->workspaceContexts()
            ->where('workspace_id', $workspace->getKey())
            ->exists();
        if (! $hasContext) {
            throw ValidationException::withMessages([
                'store' => ['Activate this workspace before connecting Meta.'],
            ]);
        }

        $driver = (string) config('services.meta.driver', 'fake');
        if ($driver === 'meta') {
            $appId = trim((string) config('services.meta.app_id'));
            $secret = trim((string) config('services.meta.app_secret'));
            $redirect = trim((string) config('services.meta.redirect_uri'));
            if ($appId === '' || $secret === '' || $redirect === '') {
                throw ValidationException::withMessages([
                    'meta' => ['Meta OAuth is not configured.'],
                ]);
            }
        } elseif (trim((string) config('services.meta.redirect_uri')) === '') {
            throw ValidationException::withMessages([
                'meta' => ['Meta OAuth redirect URI is not configured.'],
            ]);
        }

        [$plaintext, $hash] = MetaOauthState::issuePlaintextAndHash();
        $ttl = max(60, (int) config('services.meta.oauth_state_ttl', 600));

        MetaOauthState::query()->create([
            'state_hash' => $hash,
            'user_id' => $user->getKey(),
            'workspace_id' => $workspace->getKey(),
            'store_id' => $store->getKey(),
            'expires_at' => now()->addSeconds($ttl),
        ]);

        return $this->provider->authorizationUrl($plaintext);
    }
}
