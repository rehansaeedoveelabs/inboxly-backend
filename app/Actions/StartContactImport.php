<?php

namespace App\Actions;

use App\Enums\ContactImportStatus;
use App\Jobs\ProcessContactImportJob;
use App\Models\ContactImport;
use App\Models\Store;
use App\Models\User;

class StartContactImport
{
    public function __construct(private readonly CreateTag $createTag) {}

    /**
     * Phase 2 skeleton: rows arrive inline as JSON (no file upload yet) and are kept in
     * options.rows until the queued job has processed them.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $tagNames  applied to every imported contact (created if missing)
     */
    public function handle(Store $store, User $user, array $rows, array $tagNames = [], ?string $filename = null): ContactImport
    {
        $tagIds = collect($tagNames)
            ->map(fn (string $name) => $this->createTag->findOrCreate($store, $name)->getKey())
            ->unique()->values()->all();

        $import = ContactImport::create([
            'store_id' => $store->getKey(),
            'user_id' => $user->getKey(),
            'status' => ContactImportStatus::Pending,
            'original_filename' => $filename,
            'total_rows' => count($rows),
            'options' => ['rows' => array_values($rows), 'tag_ids' => $tagIds],
        ]);

        ProcessContactImportJob::dispatch($import->getKey());

        // With the sync queue the job has already run; return fresh state either way.
        return $import->refresh();
    }
}
