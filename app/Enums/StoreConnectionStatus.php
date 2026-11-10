<?php

namespace App\Enums;

enum StoreConnectionStatus: string
{
    /** Registered in Inboxly, platform authorisation not completed yet. */
    case Pending = 'pending';
    /** Real, authorised platform connection (only ever set by the platform integration). */
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case Error = 'error';
    /** Sample data store. Never a real platform connection. */
    case Demo = 'demo';
}
