<?php

namespace App\Actions\Automations;

use App\Enums\AutomationEnrollmentStatus;
use App\Enums\AutomationStatus;
use App\Enums\AutomationTriggerType;
use App\Jobs\ProcessAutomationEnrollmentJob;
use App\Models\Automation;
use App\Models\AutomationEnrollment;
use App\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class EnrollContactInAutomations
{
    public function handle(string $contactId, string $storeId, AutomationTriggerType $trigger): void
    {
        $contact = Contact::query()
            ->whereKey($contactId)
            ->where('store_id', $storeId)
            ->first();

        if ($contact === null || $contact->trashed()) {
            return;
        }

        $automations = Automation::query()
            ->where('store_id', $storeId)
            ->where('status', AutomationStatus::Active)
            ->where('trigger_type', $trigger)
            ->orderBy('id')
            ->get();

        foreach ($automations as $automation) {
            $this->enrollOnce($automation, $contact);
        }
    }

    private function enrollOnce(Automation $automation, Contact $contact): void
    {
        if ($automation->steps()->count() === 0) {
            return;
        }

        try {
            $enrollment = DB::transaction(function () use ($automation, $contact) {
                $existing = AutomationEnrollment::query()
                    ->where('automation_id', $automation->getKey())
                    ->where('contact_id', $contact->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return null;
                }

                return AutomationEnrollment::query()->create([
                    'automation_id' => $automation->getKey(),
                    'store_id' => $automation->store_id,
                    'contact_id' => $contact->getKey(),
                    'status' => AutomationEnrollmentStatus::Active,
                    'current_step_position' => 0,
                    'next_run_at' => now('UTC'),
                    'enrolled_at' => now('UTC'),
                ]);
            });
        } catch (QueryException $e) {
            // Unique (automation_id, contact_id) — duplicate trigger delivery.
            if ($this->isUniqueViolation($e)) {
                return;
            }

            throw $e;
        }

        if ($enrollment === null) {
            return;
        }

        ProcessAutomationEnrollmentJob::dispatch($enrollment->getKey());
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $sqlState === '23000' || $driverCode === 1062;
    }
}
