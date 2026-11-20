<?php

namespace App\Listeners;

use App\Actions\Automations\EnrollContactInAutomations;
use App\Enums\AutomationTriggerType;
use App\Events\CheckoutAbandoned;
use Illuminate\Contracts\Queue\ShouldQueue;

class EnrollContactOnCheckoutAbandoned implements ShouldQueue
{
    public function __construct(private EnrollContactInAutomations $enroll) {}

    public function handle(CheckoutAbandoned $event): void
    {
        $this->enroll->handle(
            $event->contactId,
            $event->storeId,
            AutomationTriggerType::CheckoutAbandoned,
        );
    }
}
