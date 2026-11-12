<?php

namespace App\Actions\Meta;

use App\Exceptions\MetaOAuthException;
use App\Models\MetaConnection;
use App\Models\MetaPage;
use App\Models\Store;
use App\Support\Meta\Contracts\MetaProvider;
use App\Support\Meta\DiscoveredMetaPage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SelectMetaPages
{
    public function __construct(private readonly MetaProvider $provider) {}

    /**
     * Persist tokens only for explicitly selected Pages. Re-fetches Page tokens server-side.
     *
     * @param  list<string>  $pageIds
     * @return list<MetaPage>
     */
    public function handle(Store $store, array $pageIds, ?string $defaultPageId = null): array
    {
        $pageIds = array_values(array_unique(array_filter(array_map(
            static fn ($id) => is_string($id) ? trim($id) : '',
            $pageIds,
        ), static fn (string $id) => $id !== '')));

        if ($pageIds === []) {
            throw ValidationException::withMessages([
                'page_ids' => ['Select at least one Facebook Page.'],
            ]);
        }

        if ($defaultPageId !== null && $defaultPageId !== '' && ! in_array($defaultPageId, $pageIds, true)) {
            throw ValidationException::withMessages([
                'default_page_id' => ['The default Page must be one of the selected Pages.'],
            ]);
        }

        $connection = MetaConnection::query()
            ->where('store_id', $store->getKey())
            ->first();

        if ($connection === null || ! $connection->isUsable()) {
            throw ValidationException::withMessages([
                'meta' => ['A usable Meta connection is required to select Pages.'],
            ]);
        }

        try {
            $discovered = $this->provider->listManagedPages((string) $connection->user_access_token);
        } catch (MetaOAuthException $e) {
            if ($e->reasonCode === 'invalid_token' || $e->reasonCode === 'permission_denied') {
                $connection->markNeedsReauth($e->reasonCode, $e->getMessage());
            }
            throw ValidationException::withMessages([
                'meta' => ['Unable to refresh Facebook Pages from Meta. Reconnect and try again.'],
            ]);
        }

        /** @var array<string, DiscoveredMetaPage> $byId */
        $byId = [];
        foreach ($discovered as $page) {
            $byId[$page->id] = $page;
        }

        foreach ($pageIds as $pageId) {
            if (! isset($byId[$pageId])) {
                throw ValidationException::withMessages([
                    'page_ids' => ["Page {$pageId} is not available for this Meta connection."],
                ]);
            }
            if (! is_string($byId[$pageId]->accessToken) || $byId[$pageId]->accessToken === '') {
                throw ValidationException::withMessages([
                    'page_ids' => ["Page {$pageId} did not return an access token. Check Page permissions."],
                ]);
            }
            // M4: only allow Pages with publish-capable tasks for selection intended for publishing.
            $tasks = $byId[$pageId]->tasks;
            $canPublish = in_array('CREATE_CONTENT', $tasks, true) || in_array('MANAGE', $tasks, true);
            if (! $canPublish) {
                throw ValidationException::withMessages([
                    'page_ids' => ["Page {$pageId} cannot publish content (missing CREATE_CONTENT task)."],
                ]);
            }
        }

        $defaultId = $defaultPageId !== null && $defaultPageId !== ''
            ? $defaultPageId
            : $pageIds[0];

        return DB::transaction(function () use ($store, $connection, $pageIds, $byId, $defaultId) {
            // Replace selection set for this store: drop pages not in the new selection.
            MetaPage::query()
                ->where('store_id', $store->getKey())
                ->whereNotIn('meta_page_id', $pageIds)
                ->delete();

            $pages = [];
            foreach ($pageIds as $pageId) {
                $discovered = $byId[$pageId];
                /** @var MetaPage $page */
                $page = MetaPage::query()->firstOrNew([
                    'store_id' => $store->getKey(),
                    'meta_page_id' => $pageId,
                ]);
                $page->forceFill([
                    'meta_connection_id' => $connection->getKey(),
                    'name' => $discovered->name,
                    'tasks' => $discovered->tasks,
                    'is_default' => $pageId === $defaultId,
                    'selected_at' => now(),
                    'page_token_refreshed_at' => now(),
                    'page_token_expires_at' => null, // Meta /accounts does not return Page token TTL
                ]);
                $page->page_access_token = $discovered->accessToken;
                $page->save();
                $pages[] = $page->fresh();
            }

            // Ensure exactly one default among remaining rows.
            MetaPage::query()
                ->where('store_id', $store->getKey())
                ->where('meta_page_id', '!=', $defaultId)
                ->update(['is_default' => false]);

            return $pages;
        });
    }
}
