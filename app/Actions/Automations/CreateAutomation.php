<?php

namespace App\Actions\Automations;

use App\Actions\Automations\Concerns\ValidatesAutomationSteps;
use App\Enums\AutomationStatus;
use App\Enums\AutomationStepType;
use App\Enums\AutomationTriggerType;
use App\Models\Automation;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateAutomation
{
    use ValidatesAutomationSteps;

    /**
     * @param  array{
     *   name: string,
     *   trigger_type?: string,
     *   steps?: list<array{type: string, config?: array<string, mixed>}>
     * }  $data
     */
    public function handle(Store $store, User $actor, array $data): Automation
    {
        $trigger = AutomationTriggerType::from(
            $data['trigger_type'] ?? AutomationTriggerType::ContactEmailSubscribed->value
        );

        $steps = $data['steps'] ?? [
            [
                'type' => AutomationStepType::SendEmail->value,
                'config' => [
                    'subject' => 'Welcome',
                    'body' => 'Thanks for subscribing.',
                ],
            ],
        ];

        $this->assertValidSteps($steps);

        return DB::transaction(function () use ($store, $actor, $data, $trigger, $steps) {
            $automation = $store->automations()->create([
                'created_by' => $actor->getKey(),
                'name' => $data['name'],
                'status' => AutomationStatus::Draft,
                'trigger_type' => $trigger,
            ]);

            foreach (array_values($steps) as $index => $step) {
                $automation->steps()->create([
                    'position' => $index,
                    'type' => AutomationStepType::from($step['type']),
                    'config' => $step['config'] ?? [],
                ]);
            }

            return $automation->load('steps');
        });
    }
}
