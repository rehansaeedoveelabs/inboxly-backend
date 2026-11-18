<?php

namespace App\Enums;

enum AutomationStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';

    public function acceptsNewEnrollments(): bool
    {
        return $this === self::Active;
    }

    public function allowsExecution(): bool
    {
        return $this === self::Active;
    }

    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Paused;
    }
}
