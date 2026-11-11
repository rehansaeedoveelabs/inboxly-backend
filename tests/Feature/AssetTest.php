<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class AssetTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    public function test_upload_list_show_download_and_delete(): void
    {
        Storage::fake('local');

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $file = UploadedFile::fake()->image('hero.png', 640, 480);

        $id = $this->spa()->actingAs($admin)->post("/api/v1/stores/{$store->id}/assets", [
            'file' => $file,
            'alt' => 'Hero image',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'hero')
            ->assertJsonPath('data.alt', 'Hero image')
            ->assertJsonPath('data.mime_type', 'image/png')
            ->assertJsonMissingPath('data.path')
            ->json('data.id');

        $asset = Asset::query()->findOrFail($id);
        Storage::disk('local')->assertExists($asset->path);

        $this->spa()->actingAs($admin)->getJson("/api/v1/stores/{$store->id}/assets")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id);

        $this->spa()->actingAs($admin)->getJson("/api/v1/assets/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id);

        $this->spa()->actingAs($admin)->get("/api/v1/assets/{$id}/download")
            ->assertOk();

        $this->spa()->actingAs($admin)->deleteJson("/api/v1/assets/{$id}")
            ->assertNoContent();

        $this->assertSoftDeleted('assets', ['id' => $id]);
        Storage::disk('local')->assertMissing($asset->path);
    }

    public function test_rejects_non_image_upload(): void
    {
        Storage::fake('local');

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $this->spa()->actingAs($admin)->post("/api/v1/stores/{$store->id}/assets", [
            'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_cross_store_access_denied(): void
    {
        Storage::fake('local');

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$otherAdmin, $otherWorkspace] = $this->adminWithWorkspace('Other');
        $otherStore = $this->storeIn($otherWorkspace);

        $asset = Asset::factory()->create([
            'store_id' => $otherStore->id,
            'uploaded_by' => $otherAdmin->id,
        ]);

        $this->spa()->actingAs($admin)->getJson("/api/v1/assets/{$asset->id}")
            ->assertForbidden();

        $outsider = User::factory()->create();
        $this->spa()->actingAs($outsider)->getJson("/api/v1/stores/{$store->id}/assets")
            ->assertForbidden();
    }
}
