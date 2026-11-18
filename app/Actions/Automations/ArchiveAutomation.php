<?php

namespace App\Actions\Automations;

use App\Enums\AutomationEnrollmentStatus;
use App\Enums\AutomationStatus;
use App\Enums\AutomationStepRunStatus;
use App\Models\Automation;
use Illuminate\Support\Facades\DB;

class ArchiveAutomation
{
    public function handle(Automation $automation): Automation
    {
        if ($automation->status === AutomationStatus::Archived) {
            return $automation->load('steps');
        }

        DB::transaction(function () use ($automation) {
            $automation->forceFill(['status' => AutomationStatus::Archived])->save();

            $activeEnrollmentIds = $automation->enrollments()
                ->where('status', AutomationEnrollmentStatus::Active)
                ->pluck('id');

            if ($activeEnrollmentIds->isEmpty()) {
                return;
            }

            DB::table('automation_enrollments')
                ->whereIn('id', $activeEnrollmentIds)
                ->update([
                    'status' => AutomationEnrollmentStatus::Cancelled->value,
                    'cancelled_at' => now(),
                    'cancel_reason' => 'automation_archived',
                    'next_run_at' => null,
                    'lease_expires_at' => null,
                    'updated_at' => now(),
                ]);

            DB::table('automation_step_runs')
                ->whereIn('enrollment_id', $activeEnrollmentIds)
                ->whereIn('status', [
                    AutomationStepRunStatus::Pending->value,
                    AutomationStepRunStatus::Running->value,
                    AutomationStepRunStatus::Failed->value,
                ])
                ->update([
                    'status' => AutomationStepRunStatus::Cancelled->value,
                    'finished_at' => now(),
                    'lease_expires_at' => null,
                    'updated_at' => now(),
                ]);
        });

        return $automation->fresh()->load('steps');
    }
}
