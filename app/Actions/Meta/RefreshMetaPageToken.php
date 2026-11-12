<?php

namespace App\Actions\Meta;

use App\Exceptions\MetaOAuthException;
use App\Models\MetaConnection;
use App\Models\MetaPage;
use App\Support\Meta\Contracts\MetaProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * H1 — Page-token preflight.
 *
 * Current Meta Pages docs describe /me/accounts Page tokens as short-lived.
 * Strategy: before publish, re-fetch the Page token with the long-lived user token
 * and update the selected MetaPage row. Never assume a stored Page token is durable.
 */
class RefreshMetaPageToken
{
    public function __construct(private readonly MetaProvider $provider) {}

    public function handle(MetaConnection $connection, MetaPage $page): MetaPage
    {
        if ($page->store_id !== $connection->store_id) {
            throw ValidationException::withMessages([
                'meta' => ['Facebook Page does not belong to this connection.'],
            ]);
        }

        $unusable = $connection->unusableReason();
        if ($unusable !== null) {
            if ($connection->status->value !== 'needs_reauth') {
                $message = match ($unusable) {
                    'data_access_expired' => 'Meta data access for this connection has expired. Reconnect Meta.',
                    'user_token_expired' => 'Meta user token is expired. Reconnect Meta.',
                    default => 'Meta connection is unusable. Reconnect Meta.',
                };
                $connection->markNeedsReauth($unusable, $message);
            }
            throw ValidationException::withMessages([
                'meta' => ['Meta connection requires reauthentication before publishing.'],
            ]);
        }

        try {
            $discovered = $this->provider->listManagedPages((string) $connection->user_access_token);
        } catch (MetaOAuthException $e) {
            if (in_array($e->reasonCode, ['invalid_token', 'permission_denied'], true)) {
                $connection->markNeedsReauth($e->reasonCode, $e->getMessage());
            }
            throw ValidationException::withMessages([
                'meta' => ['Unable to refresh the Facebook Page token. Reconnect Meta and try again.'],
            ]);
        }

        $match = null;
        foreach ($discovered as $item) {
            if ($item->id === $page->meta_page_id) {
                $match = $item;
                break;
            }
        }

        if ($match === null) {
            throw ValidationException::withMessages([
                'meta' => ['This Facebook Page is no longer available on the Meta connection. Reselect Pages.'],
            ]);
        }

        if (! is_string($match->accessToken) || $match->accessToken === '') {
            throw ValidationException::withMessages([
                'meta' => ['Meta did not return a Page access token for this Page.'],
            ]);
        }

        $tasks = $match->tasks;
        $canPublish = in_array('CREATE_CONTENT', $tasks, true) || in_array('MANAGE', $tasks, true);
        if (! $canPublish) {
            throw ValidationException::withMessages([
                'meta' => ['This Facebook Page no longer has CREATE_CONTENT permission.'],
            ]);
        }

        return DB::transaction(function () use ($page, $match) {
            /** @var MetaPage $locked */
            $locked = MetaPage::query()->whereKey($page->getKey())->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'name' => $match->name,
                'tasks' => $match->tasks,
                'page_token_refreshed_at' => now(),
                // Meta /accounts does not document a Page-token expires_in field.
                'page_token_expires_at' => null,
            ]);
            $locked->page_access_token = $match->accessToken;
            $locked->save();

            return $locked->fresh();
        });
    }
}
