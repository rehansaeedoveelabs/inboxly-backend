<?php

namespace Database\Factories;

use App\Enums\AutomationEnrollmentStatus;
use App\Models\Automation;
use App\Models\AutomationEnrollment;
use App\Models\Contact;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomationEnrollment>
 */
class AutomationEnrollmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'automation_id' => Automation::factory(),
            'store_id' => Store::factory(),
            'contact_id' => Contact::factory(),
            'status' => AutomationEnrollmentStatus::Active,
            'current_step_position' => 0,
            'next_run_at' => now('UTC'),
            'enrolled_at' => now('UTC'),
        ];
    }
}
