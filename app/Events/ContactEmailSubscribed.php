<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a contact's email consent genuinely transitions into subscribed.
 *
 * Implements ShouldDispatchAfterCommit so listeners run only after the outermost
 * database transaction commits. Nested/outer rollbacks therefore never enroll.
 */
class ContactEmailSubscribed implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $contactId,
        public readonly string $storeId,
    ) {}
}
