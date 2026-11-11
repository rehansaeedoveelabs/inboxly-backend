<?php

namespace App\Actions;

use App\Enums\ContactExportStatus;
use App\Jobs\ProcessContactExportJob;
use App\Models\ContactExport;
use App\Models\Store;
use App\Models\User;

class StartContactExport
{
    /**
     * @param  array{q?: string|null, tag?: string|null, consent_email?: string|null}  $filters
     */
    public function handle(Store $store, User $user, array $filters = []): ContactExport
    {
        $normalized = $this->normalizeFilters($filters);
        $filename = 'contacts-export-'.now()->format('Ymd-His').'.csv';

        $export = ContactExport::create([
            'store_id' => $store->getKey(),
            'user_id' => $user->getKey(),
            'status' => ContactExportStatus::Pending,
            'filters' => $normalized,
            'original_filename' => $filename,
            'total_rows' => 0,
            'processed_rows' => 0,
        ]);

        $export->forceFill([
            'disk' => 'local',
            'path' => null,
        ])->save();

        ProcessContactExportJob::dispatch($export->getKey());

        return $export->refresh();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{q?: string, tag?: string, consent_email?: string}
     */
    private function normalizeFilters(array $filters): array
    {
        $out = [];

        if (isset($filters['q']) && is_string($filters['q']) && trim($filters['q']) !== '') {
            $out['q'] = trim($filters['q']);
        }
        if (isset($filters['tag']) && is_string($filters['tag']) && $filters['tag'] !== '') {
            $out['tag'] = $filters['tag'];
        }
        if (isset($filters['consent_email']) && is_string($filters['consent_email']) && $filters['consent_email'] !== '') {
            $out['consent_email'] = $filters['consent_email'];
        }

        return $out;
    }
}
