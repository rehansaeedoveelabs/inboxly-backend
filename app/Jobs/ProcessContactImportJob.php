<?php

namespace App\Jobs;

use App\Actions\AttachTags;
use App\Actions\UpsertContact;
use App\Enums\ContactImportStatus;
use App\Models\Contact;
use App\Models\ContactImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Processes ContactImport options.rows in chunks of CHUNK_SIZE, one DB transaction per chunk.
 *
 * Idempotent: contacts are upserted by (store, email), and counters are reset when a run starts,
 * so a retry reaches the same final state instead of double counting. A row-level problem
 * (invalid email, phone owned by another contact) fails only that row; anything unexpected
 * marks the whole import "failed".
 */
class ProcessContactImportJob implements ShouldQueue
{
    use Queueable;

    public const CHUNK_SIZE = 100;

    private const MAX_RECORDED_ERRORS = 10;

    public int $tries = 1;

    public function __construct(public readonly string $contactImportId) {}

    public function handle(UpsertContact $upsertContact, AttachTags $attachTags): void
    {
        $import = ContactImport::with('store')->find($this->contactImportId);

        if ($import === null || $import->status === ContactImportStatus::Completed) {
            return;
        }

        $rows = array_values($import->options['rows'] ?? []);
        $tagIds = $import->options['tag_ids'] ?? [];
        $store = $import->store;

        if ($store === null) {
            $this->markFailed($import, 'The store no longer exists.');

            return;
        }

        $import->update([
            'status' => ContactImportStatus::Processing,
            'processed_rows' => 0, 'created_count' => 0, 'updated_count' => 0, 'failed_rows' => 0,
            'error_summary' => null,
        ]);

        $errors = [];
        $totals = ['processed_rows' => 0, 'created_count' => 0, 'updated_count' => 0, 'failed_rows' => 0];

        try {
            foreach (array_chunk($rows, self::CHUNK_SIZE, true) as $chunk) {
                DB::transaction(function () use ($chunk, $import, $store, $tagIds, $upsertContact, $attachTags, &$errors, &$totals) {
                    $created = $updated = $failed = 0;

                    foreach ($chunk as $index => $row) {
                        $data = $this->validRow($store->getKey(), $row, $error);

                        if ($data === null) {
                            $failed++;
                            $errors[] = 'Row '.($index + 1).': '.$error;

                            continue;
                        }

                        $contact = $upsertContact->handle($store, $data + ['source' => $data['source'] ?? 'import']);
                        $contact->wasRecentlyCreated ? $created++ : $updated++;

                        if ($tagIds !== []) {
                            $attachTags->handle($contact, $tagIds);
                        }
                    }

                    $totals['processed_rows'] += count($chunk);
                    $totals['created_count'] += $created;
                    $totals['updated_count'] += $updated;
                    $totals['failed_rows'] += $failed;

                    // Same transaction as the upserts: progress is never ahead of the data.
                    $import->update($totals);
                });
            }
        } catch (Throwable $e) {
            report($e);
            $this->markFailed($import, 'Import aborted: '.class_basename($e), $errors);

            return;
        }

        // Rows are PII and no longer needed once processed.
        $options = $import->options;
        unset($options['rows']);

        $import->update([
            'status' => ContactImportStatus::Completed,
            'error_summary' => $this->summary($errors, $totals['failed_rows']),
            'options' => $options,
        ]);
    }

    public function failed(Throwable $e): void
    {
        if ($import = ContactImport::find($this->contactImportId)) {
            $this->markFailed($import, 'Import aborted: '.class_basename($e));
        }
    }

    /**
     * @return array<string, mixed>|null normalised data, or null with $error set
     */
    private function validRow(string $storeId, mixed $row, ?string &$error = null): ?array
    {
        if (! is_array($row)) {
            $error = 'not an object.';

            return null;
        }

        $validator = Validator::make($row, [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9\s().-]{6,32}$/'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'locale' => ['nullable', 'string', 'max:16'],
            'timezone' => ['nullable', 'timezone:all'],
            'source' => ['nullable', 'string', 'max:64'],
            'metadata' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            $error = $validator->errors()->first();

            return null;
        }

        $data = $validator->validated();
        $data['email'] = Contact::normalizeEmail($data['email']);
        $data['phone'] = Contact::normalizePhone($data['phone'] ?? null);

        if ($data['phone'] !== null) {
            $owner = Contact::withTrashed()->where('store_id', $storeId)->where('phone', $data['phone'])->first();

            if ($owner !== null && $owner->email !== $data['email']) {
                $error = 'phone belongs to another contact.';

                return null;
            }
        }

        return $data;
    }

    /** @param  list<string>  $errors */
    private function summary(array $errors, int $failedRows): ?string
    {
        if ($errors === []) {
            return null;
        }

        $summary = implode("\n", array_slice($errors, 0, self::MAX_RECORDED_ERRORS));

        return $failedRows > self::MAX_RECORDED_ERRORS
            ? $summary."\n…and ".($failedRows - self::MAX_RECORDED_ERRORS).' more.'
            : $summary;
    }

    /** @param  list<string>  $errors */
    private function markFailed(ContactImport $import, string $message, array $errors = []): void
    {
        $import->update([
            'status' => ContactImportStatus::Failed,
            'error_summary' => trim($message."\n".implode("\n", array_slice($errors, 0, self::MAX_RECORDED_ERRORS))),
        ]);
    }
}
