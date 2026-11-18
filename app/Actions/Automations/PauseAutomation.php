<?php

namespace App\Actions\Automations;

use App\Enums\AutomationStatus;
use App\Models\Automation;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PauseAutomation
{
    public function handle(Automation $automation): Automation
    {
        if ($automation->status === AutomationStatus::Archived) {
            throw new ConflictHttpException('Archived automations cannot be paused.');
        }

        if ($automation->status === AutomationStatus::Draft) {
            throw new ConflictHttpException('Draft automations are not active.');
        }

        if ($automation->status === AutomationStatus::Paused) {
            return $automation->load('steps');
        }

        $automation->forceFill(['status' => AutomationStatus::Paused])->save();

        return $automation->fresh()->load('steps');
    }
}
