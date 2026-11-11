<?php

namespace Tests\Feature;

use App\Enums\ContactExportStatus;
use App\Jobs\ProcessContactExportJob;
use App\Models\Contact;
use App\Models\ContactExport;
use App\Models\User;
use App\Support\Audience\ContactListQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class ContactExportTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    public function test_database_queue_retry_after_exceeds_export_timeout(): void
    {
        $retryAfter = (int) Config::get('queue.connections.database.retry_after');

        $this->assertGreaterThan(
            ProcessContactExportJob::TIMEOUT_SECONDS,
            $retryAfter,
            'DB queue retry_after must exceed the export job timeout to avoid duplicate delivery.',
        );
    }

    public function test_start_export_dispatches_job_and_returns_202(): void
    {
        Queue::fake();
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/contact-exports", [
            'q' => 'jane',
            'tag' => 'vip',
            'consent_email' => 'subscribed',
        ])->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.filters.q', 'jane')
            ->assertJsonPath('data.filters.tag', 'vip')
            ->assertJsonPath('data.filters.consent_email', 'subscribed')
            ->assertJsonPath('data.download_available', false)
            ->assertJsonMissingPath('data.path')
            ->assertJsonMissingPath('data.disk');

        Queue::assertPushed(ProcessContactExportJob::class, 1);
    }

    public function test_export_writes_csv_with_consents_tags_and_escapes_formulas(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $safe = $this->contactIn($store, [
            'email' => 'safe@example.com',
            'first_name' => 'Safe',
            'last_name' => 'Person',
        ]);
        $safe->consents()->where('channel', 'email')->update([
            'status' => 'subscribed',
            'source' => 'signup_form',
            'consented_at' => '2026-01-02 03:04:05',
        ]);
        $tag = $store->tags()->create(['name' => 'VIP', 'slug' => 'vip']);
        $safe->tags()->attach($tag->id, ['created_at' => now()]);

        $this->contactIn($store, [
            'email' => 'formula@example.com',
            'first_name' => '=CMD()',
            'last_name' => "+1",
            'source' => "\tTAB",
        ]);

        $gone = $this->contactIn($store, ['email' => 'gone@example.com']);
        $gone->delete();

        $response = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/contact-exports", [])
            ->assertStatus(202);

        $export = ContactExport::query()->findOrFail($response->json('data.id'));
        $this->assertSame(ContactExportStatus::Completed, $export->status);
        $this->assertSame(2, $export->total_rows);
        $this->assertTrue($export->isDownloadable());
        $this->assertNotNull($export->expires_at);
        $this->assertTrue($export->expires_at->greaterThan(now()->addDays(6)));

        $csv = Storage::disk('local')->get($export->path);
        $this->assertStringContainsString('id,email,phone,first_name', $csv);
        $this->assertStringContainsString('safe@example.com', $csv);
        $this->assertStringContainsString('subscribed', $csv);
        $this->assertStringContainsString('signup_form', $csv);
        $this->assertStringContainsString('VIP', $csv);
        $this->assertStringContainsString("'=CMD()", $csv);
        $this->assertStringContainsString("'+1", $csv);
        $this->assertStringContainsString("'\tTAB", $csv);
        $this->assertStringNotContainsString('gone@example.com', $csv);
        $this->assertStringNotContainsString($export->path, $response->getContent());

        $download = $this->spa()->actingAs($admin)
            ->get("/api/v1/contact-exports/{$export->id}/download")
            ->assertOk();
        $this->assertStringContainsString('text/csv', (string) $download->headers->get('content-type'));
        $this->assertStringContainsString('safe@example.com', $download->streamedContent());
    }

    public function test_empty_export_produces_csv_with_headers_only(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $exportId = $this->spa()->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/contact-exports", [])
            ->assertStatus(202)
            ->json('data.id');

        $export = ContactExport::query()->findOrFail($exportId);
        $this->assertSame(ContactExportStatus::Completed, $export->status);
        $this->assertSame(0, $export->total_rows);
        $this->assertTrue($export->isDownloadable());

        $csv = Storage::disk('local')->get($export->path);
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $csv)), fn ($line) => $line !== ''));
        $this->assertCount(1, $lines, 'Empty export must contain only the header row.');
        $this->assertStringContainsString('id,email,phone,first_name', $lines[0]);
    }

    public function test_keyset_batches_include_all_contacts_with_identical_created_at(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $stamp = now()->subMinute()->startOfSecond();

        $emails = [];
        foreach (range(1, 5) as $i) {
            $contact = $this->contactIn($store, [
                'email' => "same{$i}@example.com",
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
            // Ensure DB stores the identical timestamp.
            Contact::query()->whereKey($contact->id)->update(['created_at' => $stamp]);
            $emails[] = "same{$i}@example.com";
        }

        $export = ContactExport::factory()->create([
            'store_id' => $store->id,
            'user_id' => $admin->id,
            'status' => ContactExportStatus::Pending,
        ]);

        $job = new class($export->id) extends ProcessContactExportJob
        {
            public const BATCH_SIZE = 2;
        };
        $job->handle(app(ContactListQuery::class));

        $export->refresh();
        $this->assertSame(ContactExportStatus::Completed, $export->status);
        $this->assertSame(5, $export->total_rows);

        $csv = Storage::disk('local')->get($export->path);
        foreach ($emails as $email) {
            $this->assertStringContainsString($email, $csv);
        }
    }

    public function test_duplicate_job_delivery_does_not_truncate_in_progress_export(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $this->contactIn($store, ['email' => 'a@example.com']);

        $export = ContactExport::factory()->create([
            'store_id' => $store->id,
            'user_id' => $admin->id,
            'status' => ContactExportStatus::Pending,
        ]);

        $path = 'exports/'.$store->id.'/'.$export->id.'.csv';
        Storage::disk('local')->put($path, 'ORIGINAL_PARTIAL_CONTENT');

        // Simulate first worker already claimed the export.
        $export->forceFill([
            'status' => ContactExportStatus::Processing,
            'started_at' => now(),
            'path' => $path,
            'disk' => 'local',
        ])->save();

        (new ProcessContactExportJob($export->id))->handle(app(ContactListQuery::class));

        $export->refresh();
        $this->assertSame(ContactExportStatus::Processing, $export->status);
        $this->assertSame('ORIGINAL_PARTIAL_CONTENT', Storage::disk('local')->get($path));
    }

    public function test_completed_and_failed_exports_are_not_restarted(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $this->contactIn($store, ['email' => 'a@example.com']);

        foreach ([ContactExportStatus::Completed, ContactExportStatus::Failed] as $status) {
            $path = 'exports/'.$store->id.'/'.$status->value.'.csv';
            Storage::disk('local')->put($path, 'KEEP_ME');

            $export = ContactExport::factory()->create([
                'store_id' => $store->id,
                'user_id' => $admin->id,
                'status' => $status,
                'path' => $path,
                'disk' => 'local',
                'total_rows' => 1,
                'finished_at' => now(),
                'expires_at' => now()->addDay(),
            ]);

            (new ProcessContactExportJob($export->id))->handle(app(ContactListQuery::class));

            $export->refresh();
            $this->assertSame($status, $export->status);
            $this->assertSame('KEEP_ME', Storage::disk('local')->get($path));
            $this->assertSame(1, $export->total_rows);
        }
    }

    public function test_prune_marks_stale_processing_failed_and_deletes_partial_file(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $path = 'exports/'.$store->id.'/stale.csv';
        Storage::disk('local')->put($path, 'partial');

        $stale = ContactExport::factory()->create([
            'store_id' => $store->id,
            'user_id' => $admin->id,
            'status' => ContactExportStatus::Processing,
            'path' => $path,
            'disk' => 'local',
            'started_at' => now()->subSeconds(ContactExport::STALE_PROCESSING_SECONDS + 60),
        ]);

        $active = ContactExport::factory()->create([
            'store_id' => $store->id,
            'user_id' => $admin->id,
            'status' => ContactExportStatus::Processing,
            'path' => 'exports/'.$store->id.'/active.csv',
            'disk' => 'local',
            'started_at' => now()->subMinutes(2),
        ]);
        Storage::disk('local')->put($active->path, 'active-partial');

        Artisan::call('contact-exports:prune');

        $stale->refresh();
        $this->assertSame(ContactExportStatus::Failed, $stale->status);
        $this->assertNull($stale->path);
        $this->assertNotNull($stale->finished_at);
        $this->assertNotNull($stale->expires_at);
        $this->assertStringContainsString('stalled or crashed', $stale->error_summary);
        $this->assertFalse(Storage::disk('local')->exists($path));

        $active->refresh();
        $this->assertSame(ContactExportStatus::Processing, $active->status);
        $this->assertTrue(Storage::disk('local')->exists($active->path));
    }

    public function test_stale_cleanup_cannot_fail_completed_export_or_delete_its_csv(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $path = 'exports/'.$store->id.'/completed-safe.csv';
        Storage::disk('local')->put($path, "id,email\n1,a@example.com\n");

        $export = ContactExport::factory()->create([
            'store_id' => $store->id,
            'user_id' => $admin->id,
            'status' => ContactExportStatus::Completed,
            'path' => $path,
            'disk' => 'local',
            'total_rows' => 1,
            'started_at' => now()->subSeconds(ContactExport::STALE_PROCESSING_SECONDS + 120),
            'finished_at' => now()->subMinute(),
            'expires_at' => now()->addDay(),
        ]);

        Artisan::call('contact-exports:prune');

        $export->refresh();
        $this->assertSame(ContactExportStatus::Completed, $export->status);
        $this->assertSame($path, $export->path);
        $this->assertTrue($export->isDownloadable());
        $this->assertSame("id,email\n1,a@example.com\n", Storage::disk('local')->get($path));
    }

    public function test_stale_cleanup_loses_race_to_newer_completed_state(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $path = 'exports/'.$store->id.'/race-complete.csv';
        Storage::disk('local')->put($path, "id,email\n1,winner@example.com\n");

        $export = ContactExport::factory()->create([
            'store_id' => $store->id,
            'user_id' => $admin->id,
            'status' => ContactExportStatus::Processing,
            'path' => $path,
            'disk' => 'local',
            'started_at' => now()->subSeconds(ContactExport::STALE_PROCESSING_SECONDS + 60),
        ]);

        // Job wins the race: processing → completed before prune claims stale.
        $claimed = DB::table('contact_exports')
            ->where('id', $export->id)
            ->where('status', ContactExportStatus::Processing->value)
            ->update([
                'status' => ContactExportStatus::Completed->value,
                'total_rows' => 1,
                'processed_rows' => 1,
                'byte_size' => Storage::disk('local')->size($path),
                'expires_at' => now()->addDay(),
                'finished_at' => now(),
                'error_summary' => null,
                'updated_at' => now(),
            ]);
        $this->assertSame(1, $claimed);

        Artisan::call('contact-exports:prune');

        $export->refresh();
        $this->assertSame(ContactExportStatus::Completed, $export->status);
        $this->assertSame($path, $export->path);
        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertStringContainsString('winner@example.com', Storage::disk('local')->get($path));
    }

    public function test_failed_export_cannot_subsequently_become_completed(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $export = ContactExport::factory()->create([
            'store_id' => $store->id,
            'user_id' => $admin->id,
            'status' => ContactExportStatus::Failed,
            'path' => null,
            'finished_at' => now(),
            'error_summary' => 'already failed',
        ]);

        $completed = DB::table('contact_exports')
            ->where('id', $export->id)
            ->where('status', ContactExportStatus::Processing->value)
            ->update([
                'status' => ContactExportStatus::Completed->value,
                'total_rows' => 99,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        $this->assertSame(0, $completed);
        $export->refresh();
        $this->assertSame(ContactExportStatus::Failed, $export->status);
        $this->assertSame(0, $export->total_rows);
        $this->assertSame('already failed', $export->error_summary);
    }

    public function test_failure_handling_cannot_overwrite_successful_completion(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $path = 'exports/'.$store->id.'/keep-completed.csv';
        Storage::disk('local')->put($path, "id,email\n1,safe@example.com\n");

        $export = ContactExport::factory()->create([
            'store_id' => $store->id,
            'user_id' => $admin->id,
            'status' => ContactExportStatus::Completed,
            'path' => $path,
            'disk' => 'local',
            'total_rows' => 1,
            'finished_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $job = new ProcessContactExportJob($export->id);
        $failExport = new ReflectionMethod(ProcessContactExportJob::class, 'failExport');
        $failExport->invoke($job, $export, 'late failure must not win', $path);

        $export->refresh();
        $this->assertSame(ContactExportStatus::Completed, $export->status);
        $this->assertSame($path, $export->path);
        $this->assertNull($export->error_summary);
        $this->assertSame("id,email\n1,safe@example.com\n", Storage::disk('local')->get($path));
        $this->assertTrue($export->isDownloadable());
    }

    public function test_export_filters_match_contact_list_semantics(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $alice = $this->contactIn($store, ['email' => 'alice@example.com']);
        $this->contactIn($store, ['email' => 'bob@example.com']);
        $tag = $store->tags()->create(['name' => 'VIP', 'slug' => 'vip']);
        $alice->tags()->attach($tag->id, ['created_at' => now()]);
        $alice->consents()->where('channel', 'email')->update(['status' => 'subscribed']);

        $exportId = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/contact-exports", [
            'q' => 'ali',
            'tag' => 'vip',
            'consent_email' => 'subscribed',
        ])->assertStatus(202)->json('data.id');

        $export = ContactExport::query()->findOrFail($exportId);
        $this->assertSame(1, $export->total_rows);
        $csv = Storage::disk('local')->get($export->path);
        $this->assertStringContainsString('alice@example.com', $csv);
        $this->assertStringNotContainsString('bob@example.com', $csv);
    }

    public function test_whitespace_only_q_preserves_legacy_list_filter_behaviour(): void
    {
        [, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $withEmail = $this->contactIn($store, ['email' => 'keep@example.com']);
        $this->contactIn($store, ['email' => null, 'phone' => '+15550001111']);

        // ContactListQuery (not HTTP TrimStrings): whitespace-only q trims to '' then LIKE '%',
        // which matches non-null emails only — the pre-extraction list behaviour.
        $ids = app(ContactListQuery::class)
            ->forStore($store, ['q' => '   '])
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([$withEmail->id], $ids);
    }

    public function test_export_history_is_cursor_paginated_and_store_scoped(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $other = $this->storeIn($workspace);

        foreach (range(1, 3) as $i) {
            ContactExport::factory()->create([
                'store_id' => $store->id,
                'user_id' => $admin->id,
                'created_at' => now()->subMinutes($i),
            ]);
        }
        ContactExport::factory()->create([
            'store_id' => $other->id,
            'user_id' => $admin->id,
        ]);

        $first = $this->spa()->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/contact-exports?limit=2")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertNotNull($first->json('links.next'));
        foreach ($first->json('data') as $row) {
            $this->assertSame($store->id, $row['store_id']);
        }

        $second = $this->spa()->actingAs($admin)
            ->getJson($first->json('links.next'))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $ids = [
            ...array_column($first->json('data'), 'id'),
            ...array_column($second->json('data'), 'id'),
        ];
        $this->assertCount(3, array_unique($ids));
    }

    public function test_download_and_show_are_denied_across_workspaces(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $this->contactIn($store, ['email' => 'a@example.com']);

        $exportId = $this->spa()->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/contact-exports", [])
            ->assertStatus(202)
            ->json('data.id');

        $outsider = User::factory()->create();
        $this->spa()->actingAs($outsider)
            ->getJson("/api/v1/contact-exports/{$exportId}")
            ->assertForbidden();
        $this->spa()->actingAs($outsider)
            ->get("/api/v1/contact-exports/{$exportId}/download")
            ->assertForbidden();
    }

    public function test_incomplete_expired_and_missing_files_cannot_be_downloaded(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $pending = ContactExport::factory()->create([
            'store_id' => $store->id,
            'user_id' => $admin->id,
            'status' => ContactExportStatus::Pending,
        ]);
        $this->spa()->actingAs($admin)
            ->get("/api/v1/contact-exports/{$pending->id}/download")
            ->assertStatus(409);

        $expired = ContactExport::factory()->create([
            'store_id' => $store->id,
            'user_id' => $admin->id,
            'status' => ContactExportStatus::Completed,
            'path' => 'exports/'.$store->id.'/expired.csv',
            'disk' => 'local',
            'expires_at' => now()->subMinute(),
        ]);
        Storage::disk('local')->put($expired->path, "id\n1\n");
        $this->spa()->actingAs($admin)
            ->get("/api/v1/contact-exports/{$expired->id}/download")
            ->assertStatus(410);

        $missing = ContactExport::factory()->create([
            'store_id' => $store->id,
            'user_id' => $admin->id,
            'status' => ContactExportStatus::Completed,
            'path' => 'exports/'.$store->id.'/missing.csv',
            'disk' => 'local',
            'expires_at' => now()->addDay(),
        ]);
        $this->spa()->actingAs($admin)
            ->get("/api/v1/contact-exports/{$missing->id}/download")
            ->assertNotFound();
    }

    public function test_job_failure_removes_partial_file_and_blocks_download(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $this->contactIn($store, ['email' => 'a@example.com']);

        $export = ContactExport::factory()->create([
            'store_id' => $store->id,
            'user_id' => $admin->id,
            'status' => ContactExportStatus::Pending,
        ]);

        $path = 'exports/'.$store->id.'/'.$export->id.'.csv';

        $this->mock(ContactListQuery::class, function ($mock) {
            $mock->shouldReceive('forStore')->once()->andThrow(new \RuntimeException('boom'));
        });

        (new ProcessContactExportJob($export->id))->handle(app(ContactListQuery::class));

        $export->refresh();
        $this->assertSame(ContactExportStatus::Failed, $export->status);
        $this->assertNull($export->path);
        $this->assertNotNull($export->error_summary);
        $this->assertStringContainsString('boom', $export->error_summary);
        $this->assertFalse($export->isDownloadable());
        $this->assertFalse(Storage::disk('local')->exists($path));

        $this->spa()->actingAs($admin)
            ->get("/api/v1/contact-exports/{$export->id}/download")
            ->assertStatus(409);
    }

    public function test_prune_command_deletes_expired_exports_and_files(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $expired = ContactExport::factory()->create([
            'store_id' => $store->id,
            'user_id' => $admin->id,
            'status' => ContactExportStatus::Completed,
            'path' => 'exports/'.$store->id.'/old.csv',
            'disk' => 'local',
            'expires_at' => now()->subDay(),
        ]);
        Storage::disk('local')->put($expired->path, "id\n1\n");

        $active = ContactExport::factory()->create([
            'store_id' => $store->id,
            'user_id' => $admin->id,
            'status' => ContactExportStatus::Completed,
            'path' => 'exports/'.$store->id.'/new.csv',
            'disk' => 'local',
            'expires_at' => now()->addDay(),
        ]);
        Storage::disk('local')->put($active->path, "id\n2\n");

        Artisan::call('contact-exports:prune');

        $this->assertDatabaseMissing('contact_exports', ['id' => $expired->id]);
        $this->assertFalse(Storage::disk('local')->exists('exports/'.$store->id.'/old.csv'));
        $this->assertDatabaseHas('contact_exports', ['id' => $active->id]);
        $this->assertTrue(Storage::disk('local')->exists($active->path));
    }

    public function test_outsider_cannot_start_export_and_validation_rejects_bad_consent(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $outsider = User::factory()->create();

        $this->spa()->actingAs($outsider)
            ->postJson("/api/v1/stores/{$store->id}/contact-exports", [])
            ->assertForbidden();

        $this->spa()->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/contact-exports", [
                'consent_email' => 'bogus',
            ])->assertUnprocessable();
    }
}
