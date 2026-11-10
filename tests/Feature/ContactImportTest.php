<?php

namespace Tests\Feature;

use App\Actions\AttachTags;
use App\Actions\UpsertContact;
use App\Jobs\ProcessContactImportJob;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class ContactImportTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    public function test_import_request_dispatches_job_and_creates_pending_import(): void
    {
        Queue::fake();
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/contact-imports", [
            'rows' => [['email' => 'a@example.com'], ['email' => 'b@example.com']],
        ])->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.total_rows', 2)
            ->assertJsonMissingPath('data.options');

        Queue::assertPushed(ProcessContactImportJob::class, 1);
        $this->assertSame(0, Contact::count());
    }

    public function test_job_upserts_contacts_tags_them_and_updates_counters(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $existing = $this->contactIn($store, ['email' => 'old@example.com', 'first_name' => 'Old', 'last_name' => 'Keep']);

        $response = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/contact-imports", [
            'rows' => [
                ['email' => 'New@Example.com', 'first_name' => 'New', 'phone' => '+1 555 000 1111'],
                ['email' => 'old@example.com', 'first_name' => 'Updated'],
                ['email' => 'not-an-email'],
                ['email' => 'new@example.com', 'last_name' => 'Dup'], // same contact again in the batch
            ],
            'tags' => ['Imported', 'Spring Sale'],
            'filename' => 'contacts.csv',
        ])->assertStatus(202)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.total_rows', 4)
            ->assertJsonPath('data.processed_rows', 4)
            ->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.updated_count', 2)
            ->assertJsonPath('data.failed_rows', 1)
            ->assertJsonPath('data.original_filename', 'contacts.csv');

        $this->assertStringContainsString('Row 3', $response->json('data.error_summary'));

        $this->assertSame(2, $store->contacts()->count());
        $new = $store->contacts()->where('email', 'new@example.com')->first();
        $this->assertSame('New', $new->first_name);
        $this->assertSame('Dup', $new->last_name);
        $this->assertSame('+15550001111', $new->phone);
        $this->assertSame('import', $new->source);
        $this->assertSame(3, $new->consents()->count());
        $this->assertEqualsCanonicalizing(['imported', 'spring-sale'], $new->tags()->pluck('slug')->all());

        $existing->refresh();
        $this->assertSame('Updated', $existing->first_name);
        $this->assertSame('Keep', $existing->last_name);
        $this->assertSame(2, $existing->tags()->count());

        // Raw rows are discarded once processed, and never exposed.
        $this->assertArrayNotHasKey('rows', ContactImport::first()->options);
    }

    public function test_job_is_idempotent_and_chunks_large_imports(): void
    {
        [, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $user = User::factory()->create();
        $rows = array_map(fn ($i) => ['email' => "user{$i}@example.com"], range(1, 250));

        $import = ContactImport::factory()->create([
            'store_id' => $store->id, 'user_id' => $user->id, 'total_rows' => 250,
            'options' => ['rows' => $rows, 'tag_ids' => []],
        ]);

        (new ProcessContactImportJob($import->id))->handle(app(UpsertContact::class), app(AttachTags::class));

        $import->refresh();
        $this->assertSame('completed', $import->status->value);
        $this->assertSame(250, $import->processed_rows);
        $this->assertSame(250, $import->created_count);
        $this->assertSame(250, $store->contacts()->count());

        // Re-running a finished import changes nothing.
        (new ProcessContactImportJob($import->id))->handle(app(UpsertContact::class), app(AttachTags::class));
        $this->assertSame(250, $store->contacts()->count());
        $this->assertSame(250, $import->fresh()->created_count);

        // Re-running an unfinished import (e.g. after a crash) upserts instead of duplicating or double counting.
        $import->update(['status' => 'failed', 'options' => ['rows' => $rows, 'tag_ids' => []]]);
        (new ProcessContactImportJob($import->id))->handle(app(UpsertContact::class), app(AttachTags::class));
        $import->refresh();
        $this->assertSame('completed', $import->status->value);
        $this->assertSame(250, $store->contacts()->count());
        $this->assertSame(0, $import->created_count);
        $this->assertSame(250, $import->updated_count);
    }

    public function test_import_restores_soft_deleted_contact_and_rejects_foreign_phone(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $gone = $this->contactIn($store, ['email' => 'gone@example.com']);
        $gone->delete();
        $this->contactIn($store, ['email' => 'owner@example.com', 'phone' => '+15550009999']);

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/contact-imports", [
            'rows' => [
                ['email' => 'gone@example.com'],
                ['email' => 'thief@example.com', 'phone' => '+15550009999'],
            ],
        ])->assertStatus(202)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.updated_count', 1)
            ->assertJsonPath('data.failed_rows', 1);

        $this->assertNull($gone->fresh()->deleted_at);
        $this->assertDatabaseMissing('contacts', ['email' => 'thief@example.com']);
    }

    public function test_import_marks_failed_when_unexpected_error_happens(): void
    {
        [, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $import = ContactImport::factory()->create([
            'store_id' => $store->id, 'total_rows' => 1,
            'options' => ['rows' => [['email' => 'a@example.com']], 'tag_ids' => []],
        ]);

        $this->mock(UpsertContact::class, fn ($mock) => $mock->shouldReceive('handle')->andThrow(new RuntimeException('boom')));
        $this->withoutExceptionHandling();

        dispatch_sync(new ProcessContactImportJob($import->id));

        $import->refresh();
        $this->assertSame('failed', $import->status->value);
        $this->assertStringContainsString('RuntimeException', $import->error_summary);
        $this->assertSame(0, $import->processed_rows);
        $this->assertSame(0, Contact::count());
    }

    public function test_import_fails_cleanly_when_store_was_deleted(): void
    {
        [, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $import = ContactImport::factory()->create([
            'store_id' => $store->id, 'options' => ['rows' => [['email' => 'a@example.com']]],
        ]);
        $store->delete();

        dispatch_sync(new ProcessContactImportJob($import->id));

        $this->assertSame('failed', $import->fresh()->status->value);
        $this->assertSame(0, Contact::count());
    }

    public function test_import_request_validation(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $url = "/api/v1/stores/{$store->id}/contact-imports";

        $this->spa()->actingAs($admin)->postJson($url, ['rows' => []])->assertUnprocessable();
        $this->spa()->actingAs($admin)->postJson($url, [])->assertUnprocessable();
        $this->spa()->actingAs($admin)->postJson($url, ['rows' => [['first_name' => 'No email']]])
            ->assertUnprocessable()->assertJsonValidationErrors('rows.0.email');
        $this->spa()->actingAs($admin)->postJson($url, [
            'rows' => array_fill(0, 1001, ['email' => 'a@example.com']),
        ])->assertUnprocessable()->assertJsonValidationErrors('rows');
    }

    public function test_show_import_requires_workspace_membership(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $import = ContactImport::factory()->create(['store_id' => $store->id, 'user_id' => $admin->id]);
        $marketer = $this->marketerIn($workspace);
        $outsider = User::factory()->create();

        $this->spa()->getJson("/api/v1/contact-imports/{$import->id}")->assertUnauthorized();

        $this->spa()->actingAs($admin)->getJson("/api/v1/contact-imports/{$import->id}")
            ->assertOk()->assertJsonPath('data.id', $import->id);
        $this->spa()->actingAs($marketer)->getJson("/api/v1/contact-imports/{$import->id}")->assertOk();
        $this->spa()->actingAs($outsider)->getJson("/api/v1/contact-imports/{$import->id}")->assertForbidden();
        $this->spa()->actingAs($outsider)->postJson("/api/v1/stores/{$store->id}/contact-imports", [
            'rows' => [['email' => 'a@example.com']],
        ])->assertForbidden();
    }
}
