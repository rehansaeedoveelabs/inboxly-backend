<?php

namespace Tests\Feature;

use App\Enums\FormStatus;
use App\Enums\FormType;
use App\Models\Form;
use App\Models\User;
use App\Support\Forms\FormDefinitionSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class FormTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    public function test_crud_and_summary(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $created = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/forms", [
            'name' => 'Welcome popup',
            'type' => 'popup',
            'definition' => FormDefinitionSchema::defaultDefinition(),
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Welcome popup')
            ->assertJsonPath('data.type', 'popup')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonStructure(['data' => ['public_token', 'embed_url']]);

        $id = $created->json('data.id');
        $this->assertNotEmpty($created->json('data.public_token'));
        $this->assertStringContainsString('/public/forms/', (string) $created->json('data.embed_url'));

        $this->spa()->actingAs($admin)->getJson("/api/v1/stores/{$store->id}/forms")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id);

        $this->spa()->actingAs($admin)->getJson("/api/v1/stores/{$store->id}/forms/summary")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.draft', 1)
            ->assertJsonPath('data.enabled', 0);

        $this->spa()->actingAs($admin)->putJson("/api/v1/forms/{$id}", [
            'status' => 'enabled',
            'name' => 'Welcome popup v2',
        ])->assertOk()
            ->assertJsonPath('data.status', 'enabled')
            ->assertJsonPath('data.name', 'Welcome popup v2');

        $this->spa()->actingAs($admin)->getJson("/api/v1/forms/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'enabled');

        $this->spa()->actingAs($admin)->deleteJson("/api/v1/forms/{$id}")
            ->assertNoContent();

        $this->assertSoftDeleted('forms', ['id' => $id]);
    }

    public function test_rejects_invalid_definition(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/forms", [
            'name' => 'Broken',
            'definition' => ['headline' => 'Only headline'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('definition');
    }

    public function test_supports_extended_form_types(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        foreach (['landing_page', 'flyout', 'fullscreen'] as $type) {
            $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/forms", [
                'name' => "Type {$type}",
                'type' => $type,
                'definition' => FormDefinitionSchema::defaultDefinition(),
            ])->assertCreated()
                ->assertJsonPath('data.type', $type);
        }
    }

    public function test_cross_store_access_denied(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $this->storeIn($workspace);
        [$otherAdmin, $otherWorkspace] = $this->adminWithWorkspace('Other');
        $otherStore = $this->storeIn($otherWorkspace);

        $form = Form::factory()->create([
            'store_id' => $otherStore->id,
            'created_by' => $otherAdmin->id,
            'type' => FormType::Embedded,
            'status' => FormStatus::Draft,
        ]);

        $this->spa()->actingAs($admin)->getJson("/api/v1/forms/{$form->id}")
            ->assertForbidden();

        $outsider = User::factory()->create();
        $this->spa()->actingAs($outsider)->getJson("/api/v1/stores/{$otherStore->id}/forms")
            ->assertForbidden();
    }
}
