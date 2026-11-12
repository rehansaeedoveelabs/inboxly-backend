<?php

namespace App\Actions\Meta;

use App\Exceptions\MetaOAuthException;
use App\Models\MetaConnection;
use App\Models\Store;
use App\Support\Meta\Contracts\MetaProvider;
use App\Support\Meta\DiscoveredMetaPage;
use Illuminate\Validation\ValidationException;

class DiscoverMetaPages
{
    public function __construct(private readonly MetaProvider $provider) {}

    /**
     * Server-side discovery. Returns public page metadata only (no tokens).
     *
     * @return list<array{id: string, name: string, tasks: list<string>, already_selected: bool}>
     */
    public function handle(Store $store): array
    {
        $connection = MetaConnection::query()
            ->where('store_id', $store->getKey())
            ->first();

        if ($connection === null) {
            throw ValidationException::withMessages([
                'meta' => ['Connect Meta before discovering Pages.'],
            ]);
        }

        if (! $connection->isUsable()) {
            throw ValidationException::withMessages([
                'meta' => ['Meta connection requires reauthentication before discovering Pages.'],
            ]);
        }

        try {
            $discovered = $this->provider->listManagedPages((string) $connection->user_access_token);
        } catch (MetaOAuthException $e) {
            if ($e->reasonCode === 'invalid_token' || $e->reasonCode === 'permission_denied') {
                $connection->markNeedsReauth($e->reasonCode, $e->getMessage());
            }
            throw ValidationException::withMessages([
                'meta' => ['Unable to discover Facebook Pages. Reconnect Meta and try again.'],
            ]);
        }

        $selectedIds = $connection->pages()
            ->pluck('meta_page_id')
            ->all();

        return array_map(
            function (DiscoveredMetaPage $page) use ($selectedIds) {
                $public = $page->toPublicArray();
                $public['already_selected'] = in_array($page->id, $selectedIds, true);

                return $public;
            },
            $discovered,
        );
    }
}
