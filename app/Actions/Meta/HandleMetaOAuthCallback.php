<?php

namespace App\Actions\Meta;

use App\Enums\MetaConnectionStatus;
use App\Exceptions\MetaOAuthException;
use App\Models\MetaConnection;
use App\Models\MetaOauthState;
use App\Models\Store;
use App\Support\Meta\Contracts\MetaProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class HandleMetaOAuthCallback
{
    public function __construct(private readonly MetaProvider $provider) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array{store: Store, connection: MetaConnection}
     */
    public function handle(array $query): array
    {
        if (filled($query['error'] ?? null)) {
            throw new MetaOAuthException(
                'Meta authorization was cancelled or denied.',
                'authorization_denied',
            );
        }

        $code = $query['code'] ?? null;
        $stateValue = $query['state'] ?? null;
        if (! is_string($code) || $code === '' || ! is_string($stateValue) || $stateValue === '') {
            throw new MetaOAuthException('Meta callback was missing required parameters.', 'malformed_callback');
        }

        $oauthState = $this->consumeState($stateValue);
        $store = Store::query()->with('workspace')->find($oauthState->store_id);

        if ($store === null || $store->trashed()) {
            throw new MetaOAuthException('The Inboxly store for this authorization no longer exists.', 'store_missing');
        }

        if ($store->workspace_id !== $oauthState->workspace_id) {
            throw new MetaOAuthException('Workspace mismatch for Meta authorization.', 'workspace_mismatch');
        }

        if ($store->is_demo) {
            throw new MetaOAuthException('Demo stores cannot connect to Meta.', 'demo_store');
        }

        try {
            $tokens = $this->provider->exchangeAuthorizationCode($code);
            $profile = $this->provider->fetchUserProfile($tokens['access_token']);
        } catch (MetaOAuthException $e) {
            Log::notice('Meta OAuth token exchange failed.', [
                'store_id' => $store->getKey(),
                'reason' => $e->reasonCode,
            ]);
            throw $e;
        } catch (Throwable $e) {
            Log::warning('Unexpected Meta OAuth failure.', [
                'store_id' => $store->getKey(),
            ]);
            throw new MetaOAuthException(
                'Meta authorization could not be completed.',
                'authorization_failed',
                previous: $e,
            );
        }

        // M2: persist only scopes Meta actually returned. Unknown/empty ⇒ fail closed on publish.
        $grantedScopes = $tokens['scopes'] ?? null;
        if (! is_array($grantedScopes) || $grantedScopes === []) {
            $grantedScopes = [];
        } else {
            $grantedScopes = array_values(array_filter(array_map('strval', $grantedScopes)));
        }

        $expiresAt = isset($tokens['expires_in']) && is_int($tokens['expires_in']) && $tokens['expires_in'] > 0
            ? now()->addSeconds($tokens['expires_in'])
            : null;

        // Prefer debug_token fields when Meta returns them (documented data_access_expires_at).
        // Missing inspection or zero timestamps ⇒ leave null (do not invent expiry).
        $dataAccessExpiresAt = null;
        $inspection = null;
        try {
            $inspection = $this->provider->inspectUserAccessToken((string) $tokens['access_token']);
        } catch (Throwable) {
            $inspection = null;
        }

        if (is_array($inspection)) {
            if (($inspection['is_valid'] ?? false) !== true) {
                throw new MetaOAuthException(
                    'Meta reported the access token as invalid after authorization.',
                    'invalid_token',
                );
            }
            if (isset($inspection['data_access_expires_at']) && $inspection['data_access_expires_at'] instanceof \DateTimeInterface) {
                $dataAccessExpiresAt = $inspection['data_access_expires_at'];
            }
            if ($expiresAt === null
                && isset($inspection['expires_at'])
                && $inspection['expires_at'] instanceof \DateTimeInterface) {
                $expiresAt = $inspection['expires_at'];
            }
            if ($grantedScopes === [] && isset($inspection['scopes']) && is_array($inspection['scopes'])) {
                $grantedScopes = array_values(array_filter(array_map('strval', $inspection['scopes'])));
            }
        }

        try {
            $connection = DB::transaction(function () use ($store, $tokens, $profile, $grantedScopes, $expiresAt, $dataAccessExpiresAt) {
                // M3: serialize connection updates per store.
                $lockedStore = Store::query()->whereKey($store->getKey())->lockForUpdate()->first();
                if ($lockedStore === null) {
                    throw new MetaOAuthException('The Inboxly store for this authorization no longer exists.', 'store_missing');
                }

                /** @var MetaConnection|null $existing */
                $existing = MetaConnection::query()
                    ->where('store_id', $store->getKey())
                    ->lockForUpdate()
                    ->first();

                $connection = $existing ?? new MetaConnection([
                    'store_id' => $store->getKey(),
                    'status' => MetaConnectionStatus::Connected,
                ]);

                // Reconnect: clear previously selected Pages (tokens may belong to a prior grant).
                if ($existing !== null) {
                    $existing->pages()->delete();
                }

                $connection->storeCredentials([
                    'meta_user_id' => $profile['id'],
                    'user_access_token' => $tokens['access_token'],
                    'user_token_expires_at' => $expiresAt,
                    'data_access_expires_at' => $dataAccessExpiresAt,
                    'scopes' => $grantedScopes,
                ]);

                return $connection->fresh();
            });
        } catch (MetaOAuthException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('Failed to persist Meta credentials.', [
                'store_id' => $store->getKey(),
            ]);
            throw new MetaOAuthException(
                'Meta authorization succeeded but credentials could not be saved.',
                'persist_failed',
                previous: $e,
            );
        }

        return [
            'store' => $store,
            'connection' => $connection,
        ];
    }

    private function consumeState(string $stateValue): MetaOauthState
    {
        $hash = MetaOauthState::hashState($stateValue);

        return DB::transaction(function () use ($hash) {
            /** @var MetaOauthState|null $oauthState */
            $oauthState = MetaOauthState::query()
                ->where('state_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($oauthState === null) {
                throw new MetaOAuthException('Unknown OAuth state.', 'invalid_state');
            }

            if ($oauthState->isConsumed()) {
                throw new MetaOAuthException('OAuth state was already used.', 'state_replay');
            }

            if ($oauthState->isExpired()) {
                throw new MetaOAuthException('OAuth state has expired.', 'state_expired');
            }

            $oauthState->forceFill(['consumed_at' => now()])->save();

            return $oauthState;
        });
    }
}
