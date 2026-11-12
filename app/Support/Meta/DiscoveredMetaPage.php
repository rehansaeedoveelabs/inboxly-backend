<?php

namespace App\Support\Meta;

/**
 * Server-side discovery result. accessToken must never leave the backend.
 */
final class DiscoveredMetaPage
{
    /**
     * @param  list<string>  $tasks
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $tasks,
        public readonly ?string $accessToken = null,
    ) {}

    /**
     * Safe payload for API responses (no token).
     *
     * @return array{id: string, name: string, tasks: list<string>}
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'tasks' => array_values($this->tasks),
        ];
    }
}
