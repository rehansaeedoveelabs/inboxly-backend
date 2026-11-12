<?php

namespace App\Actions\Meta;

use App\Models\MetaConnection;
use App\Models\MetaPage;
use App\Models\Store;

class ListEligibleMetaPages
{
    /**
     * @return array{
     *     connection_usable: bool,
     *     needs_reauth: bool,
     *     missing_scopes: list<string>,
     *     pages: list<array<string, mixed>>
     * }
     */
    public function handle(Store $store): array
    {
        $connection = MetaConnection::query()
            ->where('store_id', $store->getKey())
            ->first();

        if ($connection === null) {
            return [
                'connection_usable' => false,
                'needs_reauth' => false,
                'missing_scopes' => [],
                'pages' => [],
            ];
        }

        $usable = $connection->isUsable();
        $missing = $usable ? $connection->missingPublishScopes() : [];

        $pages = MetaPage::query()
            ->where('store_id', $store->getKey())
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(function (MetaPage $page) {
                return [
                    'id' => $page->getKey(),
                    'meta_page_id' => $page->meta_page_id,
                    'name' => $page->name,
                    'tasks' => $page->tasks ?? [],
                    'is_default' => (bool) $page->is_default,
                    'can_publish' => $page->canPublishContent(),
                    'page_token_refreshed_at' => optional($page->page_token_refreshed_at)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return [
            'connection_usable' => $usable && $missing === [],
            'needs_reauth' => ! $usable,
            'missing_scopes' => $missing,
            'pages' => $pages,
        ];
    }
}
