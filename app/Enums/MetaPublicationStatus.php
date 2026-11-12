<?php

namespace App\Enums;

enum MetaPublicationStatus: string
{
    case Draft = 'draft';
    case Queued = 'queued';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';
    case OutcomeUnknown = 'outcome_unknown';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Published,
            self::Failed,
            self::Cancelled,
        ], true);
    }

    public function blocksDuplicate(): bool
    {
        return in_array($this, [
            self::Queued,
            self::Publishing,
            self::Published,
            self::OutcomeUnknown,
        ], true);
    }
}
