<?php

namespace App\Listeners;

use App\Actions\Automations\EnrollContactInAutomations;
use App\Enums\AutomationTriggerType;
use App\Events\ContactEmailSubscribed;
use Illuminate\Contracts\Queue\ShouldQueue;

class EnrollContactOnEmailSubscribed implements ShouldQueue
{
    public function __construct(private EnrollContactInAutomations $enroll) {}

    public function handle(ContactEmailSubscribed $event): void
    {
        $this->enroll->handle(
            $event->contactId,
            $event->storeId,
            AutomationTriggerType::ContactEmailSubscribed,
        );
    }
}
