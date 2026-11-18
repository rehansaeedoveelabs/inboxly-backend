<?php

namespace Database\Factories;

use App\Enums\AutomationStepRunStatus;
use App\Models\AutomationEnrollment;
use App\Models\AutomationStep;
use App\Models\AutomationStepRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AutomationStepRun>
 */
class AutomationStepRunFactory extends Factory
{
    public function definition(): array
    {
        $enrollment = AutomationEnrollment::factory();
        $step = AutomationStep::factory();

        return [
            'enrollment_id' => $enrollment,
            'automation_step_id' => $step,
            'attempt' => 0,
            'status' => AutomationStepRunStatus::Pending,
            'idempotency_key' => 'automation:'.Str::ulid().':'.Str::ulid(),
        ];
    }
}
