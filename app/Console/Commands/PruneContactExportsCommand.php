<?php

namespace App\Console\Commands;

use App\Enums\ContactExportStatus;
use App\Models\ContactExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PruneContactExportsCommand extends Command
{
    protected $signature = 'contact-exports:prune';

    protected $description = 'Fail stale processing exports and delete expired contact export files/records.';

    public function handle(): int
    {
        $failedStale = $this->failStaleProcessing();
        $deleted = $this->deleteExpired();

        $this->info("Marked {$failedStale} stale processing export(s) as failed.");
        $this->info("Pruned {$deleted} expired contact export(s).");

        return self::SUCCESS;
    }

    private function failStaleProcessing(): int
    {
        $count = 0;
        $cutoff = now()->subSeconds(ContactExport::STALE_PROCESSING_SECONDS);

        ContactExport::query()
            ->where('status', ContactExportStatus::Processing)
            ->where(function ($query) use ($cutoff) {
                $query->where('started_at', '<=', $cutoff)
                    ->orWhere(function ($inner) use ($cutoff) {
                        $inner->whereNull('started_at')
                            ->where('updated_at', '<=', $cutoff);
                    });
            })
            ->orderBy('id')
            ->chunkById(100, function ($exports) use (&$count, $cutoff) {
                foreach ($exports as $export) {
                    $path = $export->path;
                    $disk = $export->disk ?: 'local';

                    // Re-claim atomically: only fail if still processing and still stale.
                    $claimed = DB::table('contact_exports')
                        ->where('id', $export->getKey())
                        ->where('status', ContactExportStatus::Processing->value)
                        ->where(function ($query) use ($cutoff) {
                            $query->where('started_at', '<=', $cutoff)
                                ->orWhere(function ($inner) use ($cutoff) {
                                    $inner->whereNull('started_at')
                                        ->where('updated_at', '<=', $cutoff);
                                });
                        })
                        ->update([
                            'status' => ContactExportStatus::Failed->value,
                            'path' => null,
                            'byte_size' => null,
                            'error_summary' => 'Export aborted: worker stalled or crashed before completion.',
                            'expires_at' => now()->addDays(ContactExport::EXPIRY_DAYS),
                            'finished_at' => now(),
                            'updated_at' => now(),
                        ]);

                    if ($claimed !== 1) {
                        continue;
                    }

                    if (is_string($path) && $path !== '') {
                        try {
                            Storage::disk($disk)->delete($path);
                        } catch (Throwable) {
                            // Best-effort file cleanup after a successful stale claim.
                        }
                    }

                    $count++;
                }
            });

        return $count;
    }

    private function deleteExpired(): int
    {
        $deleted = 0;

        ContactExport::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($exports) use (&$deleted) {
                foreach ($exports as $export) {
                    if (is_string($export->path) && $export->path !== '') {
                        try {
                            Storage::disk($export->disk ?: 'local')->delete($export->path);
                        } catch (Throwable) {
                            // Continue pruning DB rows even if the file is already gone.
                        }
                    }

                    $export->delete();
                    $deleted++;
                }
            });

        return $deleted;
    }
}
