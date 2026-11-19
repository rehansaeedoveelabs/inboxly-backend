<?php

namespace App\Jobs;

use App\Actions\Shopify\PushContactToShopify;
use App\Models\Contact;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PushContactToShopifyJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $contactId) {}

    public function handle(PushContactToShopify $push): void
    {
        $contact = Contact::query()->find($this->contactId);
        if ($contact === null) {
            return;
        }

        $push->handle($contact);
    }
}
