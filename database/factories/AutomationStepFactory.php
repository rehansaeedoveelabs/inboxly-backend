<?php

namespace Database\Factories;

use App\Enums\AutomationStepType;
use App\Models\Automation;
use App\Models\AutomationStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomationStep>
 */
class AutomationStepFactory extends Factory
{
    public function definition(): array
    {
        return [
            'automation_id' => Automation::factory(),
            'position' => 0,
            'type' => AutomationStepType::SendEmail,
            'config' => [
                'subject' => 'Welcome',
                'body' => 'Thanks for subscribing.',
            ],
        ];
    }

    public function delay(int $minutes = 60, int $position = 0): static
    {
        return $this->state(fn () => [
            'position' => $position,
            'type' => AutomationStepType::Delay,
            'config' => ['delay_minutes' => $minutes],
        ]);
    }

    public function sendEmail(string $subject = 'Welcome', string $body = 'Hello', int $position = 0): static
    {
        return $this->state(fn () => [
            'position' => $position,
            'type' => AutomationStepType::SendEmail,
            'config' => [
                'subject' => $subject,
                'body' => $body,
            ],
        ]);
    }
}
