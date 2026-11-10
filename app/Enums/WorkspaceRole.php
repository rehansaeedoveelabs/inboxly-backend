<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Admin = 'admin';
    case Marketer = 'marketer';

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }
}
