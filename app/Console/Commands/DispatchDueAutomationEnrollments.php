<?php

namespace App\Console\Commands;

use App\Enums\AutomationEnrollmentStatus;
use App\Enums\AutomationStatus;
use App\Jobs\ProcessAutomationEnrollmentJob;
use App\Models\AutomationEnrollment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchDueAutomationEnrollments extends Command
{
    protected $signature = 'automations:dispatch-due {--limit=50 : Maximum enrollments to dispatch}';

    protected $description = 'Dispatch due automation enrollments (delays + lease recovery).';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        // Reclaim expired running leases on step runs so interrupted workers can retry.
        DB::table('automation_step_runs')
            ->where('status', 'running')
            ->where(function ($query) {
                $query->whereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '<=', now());
            })
            ->update([
                'status' => 'pending',
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);

        DB::table('automation_enrollments')
            ->where('status', AutomationEnrollmentStatus::Active->value)
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<=', now())
            ->update([
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);

        $enrollmentIds = AutomationEnrollment::query()
            ->where('status', AutomationEnrollmentStatus::Active)
            ->where(function ($query) {
                $query->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', now());
            })
            ->whereHas('automation', fn ($q) => $q->where('status', AutomationStatus::Active))
            ->orderBy('next_run_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($enrollmentIds as $id) {
            ProcessAutomationEnrollmentJob::dispatch($id);
        }

        $this->info('Dispatched '.$enrollmentIds->count().' automation enrollment(s).');

        return self::SUCCESS;
    }
}
