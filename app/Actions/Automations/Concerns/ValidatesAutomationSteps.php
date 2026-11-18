<?php

namespace App\Actions\Automations\Concerns;

use App\Enums\AutomationStepType;
use Illuminate\Validation\ValidationException;

trait ValidatesAutomationSteps
{
    /**
     * @param  list<array{type: string, config?: array<string, mixed>}>  $steps
     */
    private function assertValidSteps(array $steps): void
    {
        if ($steps === []) {
            throw ValidationException::withMessages([
                'steps' => ['At least one step is required.'],
            ]);
        }

        $hasEmail = false;
        $maxIndex = count($steps) - 1;

        foreach ($steps as $index => $step) {
            $type = $step['type'] ?? null;
            if (! is_string($type) || AutomationStepType::tryFrom($type) === null) {
                throw ValidationException::withMessages([
                    "steps.$index.type" => ['Invalid step type.'],
                ]);
            }

            if ($type === AutomationStepType::SendEmail->value) {
                $hasEmail = true;
                $subject = trim((string) ($step['config']['subject'] ?? ''));
                $body = (string) ($step['config']['body'] ?? '');
                $html = (string) ($step['config']['html_body'] ?? '');
                if ($subject === '') {
                    throw ValidationException::withMessages([
                        "steps.$index.config.subject" => ['Email subject is required.'],
                    ]);
                }
                if ($body === '' && $html === '') {
                    throw ValidationException::withMessages([
                        "steps.$index.config.body" => ['Email body is required.'],
                    ]);
                }
            }

            if ($type === AutomationStepType::Delay->value) {
                $minutes = (int) ($step['config']['delay_minutes'] ?? -1);
                if ($minutes < 0) {
                    throw ValidationException::withMessages([
                        "steps.$index.config.delay_minutes" => ['Delay minutes must be 0 or greater.'],
                    ]);
                }
            }

            if ($type === AutomationStepType::Condition->value) {
                $this->assertValidConditionStep($index, $step['config'] ?? [], $maxIndex);
            }
        }

        if (! $hasEmail) {
            throw ValidationException::withMessages([
                'steps' => ['An automation must include a send_email step.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function assertValidConditionStep(int $index, array $config, int $maxIndex): void
    {
        $predicate = $config['predicate'] ?? null;
        if (! in_array($predicate, ['email_subscribed', 'has_tag'], true)) {
            throw ValidationException::withMessages([
                "steps.$index.config.predicate" => ['Condition predicate must be email_subscribed or has_tag.'],
            ]);
        }

        if ($predicate === 'has_tag') {
            $tagId = trim((string) ($config['tag_id'] ?? ''));
            if ($tagId === '') {
                throw ValidationException::withMessages([
                    "steps.$index.config.tag_id" => ['tag_id is required for has_tag conditions.'],
                ]);
            }
        }

        foreach (['on_true_goto', 'on_false_goto'] as $key) {
            $target = $config[$key] ?? 'next';
            if ($target === 'next' || $target === 'end') {
                continue;
            }
            if (! is_int($target) && ! (is_string($target) && ctype_digit($target))) {
                throw ValidationException::withMessages([
                    "steps.$index.config.$key" => ['Goto must be next, end, or a step position.'],
                ]);
            }
            $position = (int) $target;
            if ($position < 0 || $position > $maxIndex) {
                throw ValidationException::withMessages([
                    "steps.$index.config.$key" => ['Goto position is out of range.'],
                ]);
            }
            if ($position === $index) {
                throw ValidationException::withMessages([
                    "steps.$index.config.$key" => ['Condition cannot jump to itself.'],
                ]);
            }
        }
    }
}
