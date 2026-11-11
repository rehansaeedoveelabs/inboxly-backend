<?php

namespace App\Jobs;

use App\Enums\ConsentChannel;
use App\Enums\ContactExportStatus;
use App\Models\Contact;
use App\Models\ContactExport;
use App\Support\Audience\ContactListQuery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Streams a private CSV of active store contacts in keyset batches ordered by
 * (created_at DESC, id DESC). Eager-loads consents/tags per batch only.
 */
class ProcessContactExportJob implements ShouldQueue
{
    use Queueable;

    public const BATCH_SIZE = 500;

    public const MAX_ERROR_LENGTH = 2000;

    public const TIMEOUT_SECONDS = 300;

    public int $tries = 1;

    public int $timeout = self::TIMEOUT_SECONDS;

    public function __construct(public readonly string $contactExportId) {}

    public function handle(ContactListQuery $contactListQuery): void
    {
        $export = ContactExport::query()->find($this->contactExportId);

        if ($export === null) {
            return;
        }

        // Completed/failed must never restart. Only pending may be claimed.
        if ($export->status !== ContactExportStatus::Pending) {
            return;
        }

        $store = $export->store;
        if ($store === null) {
            $this->failExport($export, 'The store no longer exists.', $export->path);

            return;
        }

        $path = 'exports/'.$store->getKey().'/'.$export->getKey().'.csv';
        $disk = 'local';

        // Atomic claim: only one worker can transition pending → processing.
        $claimed = DB::table('contact_exports')
            ->where('id', $export->getKey())
            ->where('status', ContactExportStatus::Pending->value)
            ->update([
                'status' => ContactExportStatus::Processing->value,
                'started_at' => now(),
                'path' => $path,
                'disk' => $disk,
                'processed_rows' => 0,
                'total_rows' => 0,
                'byte_size' => null,
                'error_summary' => null,
                'finished_at' => null,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return;
        }

        $export->refresh();

        $absolute = Storage::disk($disk)->path($path);
        $directory = dirname($absolute);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->failExport($export, 'Unable to create export storage directory.', $path);

            return;
        }

        // Exclusive create: fail if another writer already created the file.
        $handle = fopen($absolute, 'xb');
        if ($handle === false) {
            $this->failExport($export, 'Unable to create export file exclusively.', $path);

            return;
        }

        try {
            // UTF-8 BOM helps Excel recognise encoding.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $this->headers());

            $filters = is_array($export->filters) ? $export->filters : [];
            $base = $contactListQuery
                ->forStore($store, $filters)
                ->with(['consents', 'tags'])
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            $processed = 0;
            $cursorCreatedAt = null;
            $cursorId = null;

            while (true) {
                $batchQuery = clone $base;
                if ($cursorCreatedAt !== null && $cursorId !== null) {
                    $batchQuery->where(function ($query) use ($cursorCreatedAt, $cursorId) {
                        $query->where('created_at', '<', $cursorCreatedAt)
                            ->orWhere(function ($inner) use ($cursorCreatedAt, $cursorId) {
                                $inner->where('created_at', '=', $cursorCreatedAt)
                                    ->where('id', '<', $cursorId);
                            });
                    });
                }

                /** @var \Illuminate\Database\Eloquent\Collection<int, Contact> $batch */
                $batch = $batchQuery->limit(static::BATCH_SIZE)->get();
                if ($batch->isEmpty()) {
                    break;
                }

                foreach ($batch as $contact) {
                    fputcsv($handle, $this->row($contact));
                    $processed++;
                }

                $last = $batch->last();
                $cursorCreatedAt = $last->created_at?->toDateTimeString() ?? (string) $last->getAttribute('created_at');
                $cursorId = $last->getKey();

                $export->forceFill(['processed_rows' => $processed])->save();
            }

            fflush($handle);
            fclose($handle);
            $handle = null;

            if (! is_file($absolute)) {
                $this->failExport($export, 'Export file was not written.', $path);

                return;
            }

            // Claim completion only while still processing — never revive failed/stale rows.
            $completed = DB::table('contact_exports')
                ->where('id', $export->getKey())
                ->where('status', ContactExportStatus::Processing->value)
                ->update([
                    'status' => ContactExportStatus::Completed->value,
                    'total_rows' => $processed,
                    'processed_rows' => $processed,
                    'byte_size' => filesize($absolute) ?: 0,
                    'expires_at' => now()->addDays(ContactExport::EXPIRY_DAYS),
                    'finished_at' => now(),
                    'error_summary' => null,
                    'updated_at' => now(),
                ]);

            if ($completed !== 1) {
                // Lost to fail/stale claim: remove the file we wrote; DB already owns the outcome.
                $this->deleteExportFile($disk, $path);

                return;
            }

            $export->refresh();
        } catch (Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            report($e);
            $this->failExport(
                $export,
                'Export aborted: '.class_basename($e).' — '.$this->bound($e->getMessage()),
                $path,
            );
        }
    }

    public function failed(?Throwable $e): void
    {
        $export = ContactExport::query()->find($this->contactExportId);
        if ($export === null) {
            return;
        }

        $this->failExport(
            $export,
            'Export aborted: '.($e ? class_basename($e) : 'unknown'),
            $export->path,
        );
    }

    /** @return list<string> */
    private function headers(): array
    {
        return [
            'id',
            'email',
            'phone',
            'first_name',
            'last_name',
            'locale',
            'timezone',
            'source',
            'email_consent',
            'sms_consent',
            'push_consent',
            'email_consent_source',
            'sms_consent_source',
            'push_consent_source',
            'email_consented_at',
            'sms_consented_at',
            'push_consented_at',
            'tags',
            'consent_review_required',
            'created_at',
            'updated_at',
        ];
    }

    /** @return list<string> */
    private function row(Contact $contact): array
    {
        $consent = fn (ConsentChannel $channel) => $contact->consents
            ->first(fn ($c) => $c->channel === $channel);

        $email = $consent(ConsentChannel::Email);
        $sms = $consent(ConsentChannel::Sms);
        $push = $consent(ConsentChannel::Push);

        $tags = $contact->tags
            ->pluck('name')
            ->filter()
            ->values()
            ->implode(';');

        return [
            $this->cell((string) $contact->getKey()),
            $this->cell($contact->email),
            $this->cell($contact->phone),
            $this->cell($contact->first_name),
            $this->cell($contact->last_name),
            $this->cell($contact->locale),
            $this->cell($contact->timezone),
            $this->cell($contact->source),
            $this->cell($email?->status?->value),
            $this->cell($sms?->status?->value),
            $this->cell($push?->status?->value),
            $this->cell($email?->source),
            $this->cell($sms?->source),
            $this->cell($push?->source),
            $this->cell($email?->consented_at?->toIso8601String()),
            $this->cell($sms?->consented_at?->toIso8601String()),
            $this->cell($push?->consented_at?->toIso8601String()),
            $this->cell($tags),
            $contact->requiresConsentReview() ? 'true' : 'false',
            $this->cell($contact->created_at?->toIso8601String()),
            $this->cell($contact->updated_at?->toIso8601String()),
        ];
    }

    private function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $string = (string) $value;
        // Mitigate CSV formula / DDE injection when opened in spreadsheet apps.
        if ($string !== '' && preg_match('/^[=+\-@\t\r]/', $string) === 1) {
            return "'".$string;
        }

        return $string;
    }

    /**
     * Atomically claim pending|processing → failed, then delete the partial file.
     * File deletion runs only after the DB claim succeeds so a completed export's
     * CSV cannot be removed by a late failure path.
     */
    private function failExport(ContactExport $export, string $message, ?string $path): void
    {
        $disk = $export->disk ?: 'local';

        $failed = DB::table('contact_exports')
            ->where('id', $export->getKey())
            ->whereIn('status', [
                ContactExportStatus::Pending->value,
                ContactExportStatus::Processing->value,
            ])
            ->update([
                'status' => ContactExportStatus::Failed->value,
                'path' => null,
                'byte_size' => null,
                'error_summary' => $this->bound($message),
                'expires_at' => now()->addDays(ContactExport::EXPIRY_DAYS),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        if ($failed !== 1) {
            return;
        }

        $export->refresh();
        $this->deleteExportFile($disk, $path);
    }

    private function deleteExportFile(string $disk, ?string $path): void
    {
        if (! is_string($path) || $path === '') {
            return;
        }

        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable) {
            // Best-effort cleanup.
        }
    }

    private function bound(string $message): string
    {
        $message = trim($message);

        return mb_strlen($message) > self::MAX_ERROR_LENGTH
            ? mb_substr($message, 0, self::MAX_ERROR_LENGTH - 1).'…'
            : $message;
    }
}
