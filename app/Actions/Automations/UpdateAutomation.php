<?php

namespace App\Actions\Automations;

use App\Actions\Automations\Concerns\ValidatesAutomationSteps;
use App\Enums\AutomationStatus;
use App\Enums\AutomationStepType;
use App\Models\Automation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class UpdateAutomation
{
    use ValidatesAutomationSteps;

    /**
     * @param  array{
     *   name?: string,
     *   steps?: list<array{type: string, config?: array<string, mixed>}>
     * }  $data
     */
    public function handle(Automation $automation, array $data): Automation
    {
        if ($automation->status === AutomationStatus::Archived) {
            throw new ConflictHttpException('Archived automations cannot be edited.');
        }

        if ($automation->status === AutomationStatus::Active) {
            throw new ConflictHttpException('Pause the automation before editing steps.');
        }

        return DB::transaction(function () use ($automation, $data) {
            if (isset($data['name'])) {
                $automation->forceFill(['name' => $data['name']])->save();
            }

            if (array_key_exists('steps', $data)) {
                $steps = $data['steps'] ?? [];
                $this->assertValidSteps($steps);

                $automation->steps()->delete();
                foreach (array_values($steps) as $index => $step) {
                    $automation->steps()->create([
                        'position' => $index,
                        'type' => AutomationStepType::from($step['type']),
                        'config' => $step['config'] ?? [],
                    ]);
                }
            }

            return $automation->fresh()->load('steps');
        });
    }
}
