<?php

namespace App\Http\Resources;

use App\Models\ShopifySyncRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ShopifySyncRun */
class ShopifySyncRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'resources' => $this->resources,
            'status' => $this->status->value,
            'customers_status' => $this->customers_status->value,
            'products_status' => $this->products_status->value,
            'orders_status' => $this->orders_status->value,
            'customers_synced' => (int) $this->customers_synced,
            'products_synced' => (int) $this->products_synced,
            'orders_synced' => (int) $this->orders_synced,
            'customers_cursor' => $this->customers_cursor,
            'products_cursor' => $this->products_cursor,
            'orders_cursor' => $this->orders_cursor,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'lease_expires_at' => $this->lease_expires_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
