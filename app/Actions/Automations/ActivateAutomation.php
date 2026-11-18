<?php

namespace App\Actions\Automations;

use App\Enums\AutomationEnrollmentStatus;
use App\Enums\AutomationStatus;
use App\Jobs\ProcessAutomationEnrollmentJob;
use App\Models\Automation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ActivateAutomation
{
    public function handle(Automation $automation): Automation
    {
        if ($automation->status === AutomationStatus::Archived) {
            throw new ConflictHttpException('Archived automations cannot be activated.');
        }

        if ($automation->status === AutomationStatus::Active) {
            return $automation->load('steps');
        }

        if ($automation->steps()->count() === 0) {
            throw ValidationException::withMessages([
                'steps' => ['Add at least one step before activating.'],
            ]);
        }

        if (! $automation->steps()->where('type', 'send_email')->exists()) {
            throw ValidationException::withMessages([
                'steps' => ['A send_email step is required before activating.'],
            ]);
        }

        DB::transaction(function () use ($automation) {
            $automation->forceFill(['status' => AutomationStatus::Active])->save();
        });

        // Resume frozen enrollments that are already due.
        $dueIds = $automation->enrollments()
            ->where('status', AutomationEnrollmentStatus::Active)
            ->where(function ($query) {
                $query->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(100)
            ->pluck('id');

        foreach ($dueIds as $enrollmentId) {
            ProcessAutomationEnrollmentJob::dispatch($enrollmentId);
        }

        return $automation->fresh()->load('steps');
    }
}
