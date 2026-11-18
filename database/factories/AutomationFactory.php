<?php

namespace Database\Factories;

use App\Enums\AutomationStatus;
use App\Enums\AutomationTriggerType;
use App\Models\Automation;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Automation>
 */
class AutomationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'created_by' => User::factory(),
            'name' => 'Welcome email',
            'status' => AutomationStatus::Draft,
            'trigger_type' => AutomationTriggerType::ContactEmailSubscribed,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => AutomationStatus::Active]);
    }

    public function paused(): static
    {
        return $this->state(fn () => ['status' => AutomationStatus::Paused]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => AutomationStatus::Archived]);
    }
}
