<?php

namespace Tests\Feature;

use App\Enums\SmsTemplateStatus;
use App\Models\SmsTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class SmsTemplateTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    public function test_crud_and_duplicate(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $id = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/sms-templates", [
            'name' => 'Welcome offer',
            'category' => 'welcome',
            'body' => 'Welcome to {{shop_name}}, {{first_name}}! Code WELCOME15: {{sale_url}}',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Welcome offer')
            ->assertJsonPath('data.category', 'welcome')
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $this->spa()->actingAs($admin)->getJson("/api/v1/stores/{$store->id}/sms-templates")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->spa()->actingAs($admin)->putJson("/api/v1/sms-templates/{$id}", [
            'status' => 'published',
            'name' => 'Welcome offer v2',
        ])->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.name', 'Welcome offer v2');

        $copyId = $this->spa()->actingAs($admin)->postJson("/api/v1/sms-templates/{$id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $this->assertNotSame($id, $copyId);

        $this->spa()->actingAs($admin)->deleteJson("/api/v1/sms-templates/{$copyId}")
            ->assertNoContent();

        $this->assertSoftDeleted('sms_templates', ['id' => $copyId]);
    }

    public function test_rejects_empty_body_and_cross_store(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/sms-templates", [
            'name' => 'Broken',
            'body' => '',
        ])->assertUnprocessable();

        [$otherAdmin, $otherWorkspace] = $this->adminWithWorkspace('Other');
        $otherStore = $this->storeIn($otherWorkspace);
        $template = SmsTemplate::factory()->create([
            'store_id' => $otherStore->id,
            'created_by' => $otherAdmin->id,
            'status' => SmsTemplateStatus::Draft,
        ]);

        $this->spa()->actingAs($admin)->getJson("/api/v1/sms-templates/{$template->id}")
            ->assertForbidden();

        $outsider = User::factory()->create();
        $this->spa()->actingAs($outsider)->getJson("/api/v1/stores/{$store->id}/sms-templates")
            ->assertForbidden();
    }
}
