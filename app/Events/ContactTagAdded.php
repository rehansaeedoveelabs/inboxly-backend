<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when one or more tags are newly attached to a contact.
 *
 * Implements ShouldDispatchAfterCommit so listeners run only after the outermost
 * database transaction commits.
 */
class ContactTagAdded implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<string>  $tagIds  Newly attached tag ids (not previously linked).
     */
    public function __construct(
        public readonly string $contactId,
        public readonly string $storeId,
        public readonly array $tagIds,
    ) {}
}
