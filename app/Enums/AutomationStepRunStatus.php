<?php

namespace App\Enums;

enum AutomationStepRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Skipped, self::Cancelled => true,
            default => false,
        };
    }

    public function isSuccessfulOutcome(): bool
    {
        return $this === self::Succeeded || $this === self::Skipped;
    }
}
