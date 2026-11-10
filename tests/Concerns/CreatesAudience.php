<?php

namespace Tests\Concerns;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Models\Contact;
use App\Models\Store;
use App\Models\Workspace;

trait CreatesAudience
{
    protected function storeIn(Workspace $workspace): Store
    {
        return Store::factory()->create(['workspace_id' => $workspace->getKey()]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function contactIn(Store $store, array $attributes = []): Contact
    {
        $contact = Contact::factory()->create(['store_id' => $store->getKey()] + $attributes);

        foreach (ConsentChannel::cases() as $channel) {
            $contact->consents()->create(['channel' => $channel, 'status' => ConsentStatus::Nonsubscribed]);
        }

        return $contact;
    }
}
