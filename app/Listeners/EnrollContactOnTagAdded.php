<?php

namespace App\Listeners;

use App\Actions\Automations\EnrollContactInAutomations;
use App\Enums\AutomationTriggerType;
use App\Events\ContactTagAdded;
use Illuminate\Contracts\Queue\ShouldQueue;

class EnrollContactOnTagAdded implements ShouldQueue
{
    public function __construct(private EnrollContactInAutomations $enroll) {}

    public function handle(ContactTagAdded $event): void
    {
        $this->enroll->handle(
            $event->contactId,
            $event->storeId,
            AutomationTriggerType::ContactTagAdded,
        );
    }
}
