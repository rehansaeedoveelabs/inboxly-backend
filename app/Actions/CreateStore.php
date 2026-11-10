<?php

namespace App\Actions;

use App\Enums\StoreConnectionStatus;
use App\Enums\StorePlatform;
use App\Models\Store;
use App\Models\Workspace;

class CreateStore
{
    /**
     * Clients cannot choose connection_status: a new store is "pending" until the
     * platform integration authorises it, and a demo store is always "demo".
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(Workspace $workspace, array $data): Store
    {
        $isDemo = (bool) ($data['is_demo'] ?? false);

        return $workspace->stores()->create([
            'name' => $data['name'],
            'domain' => $data['domain'] ?? null,
            'platform' => $data['platform'] ?? StorePlatform::Shopify,
            'connection_status' => $isDemo ? StoreConnectionStatus::Demo : StoreConnectionStatus::Pending,
            'timezone' => $data['timezone'] ?? 'UTC',
            'currency' => strtoupper($data['currency'] ?? 'USD'),
            'metadata' => $data['metadata'] ?? null,
            'is_demo' => $isDemo,
        ]);
    }
}
