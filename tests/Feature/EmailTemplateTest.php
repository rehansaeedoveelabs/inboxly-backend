<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class EmailTemplateTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    /**
     * @return array{version: int, settings: array<string, mixed>, blocks: list<array<string, mixed>>}
     */
    private function sampleDocument(): array
    {
        return [
            'version' => 1,
            'settings' => ['width' => 600, 'background' => '#ffffff'],
            'blocks' => [
                [
                    'id' => 'blk_1',
                    'type' => 'header',
                    'props' => ['html' => '<p><strong>Hello</strong></p>', 'align' => 'center'],
                ],
                [
                    'id' => 'blk_2',
                    'type' => 'text',
                    'props' => ['html' => '<p>Welcome <script>alert(1)</script>friend</p>', 'align' => 'left'],
                ],
                [
                    'id' => 'blk_3',
                    'type' => 'button',
                    'props' => ['label' => 'Shop now', 'href' => 'https://example.com'],
                ],
            ],
        ];
    }

    public function test_crud_preview_duplicate_and_sanitization(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $document = $this->sampleDocument();

        $id = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/email-templates", [
            'name' => 'Welcome email',
            'category' => 'onboarding',
            'status' => 'draft',
            'document' => $document,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Welcome email')
            ->assertJsonPath('data.category', 'onboarding')
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $this->spa()->actingAs($admin)->getJson("/api/v1/email-templates/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.document.blocks.0.type', 'header');

        $html = $this->spa()->actingAs($admin)->getJson("/api/v1/email-templates/{$id}")
            ->json('data.html');
        $this->assertIsString($html);
        $this->assertStringContainsString('Welcome', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('Shop now', $html);

        $this->spa()->actingAs($admin)->putJson("/api/v1/email-templates/{$id}", [
            'name' => 'Welcome email v2',
            'status' => 'published',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Welcome email v2')
            ->assertJsonPath('data.status', 'published');

        $preview = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/email-templates/preview", [
            'document' => $document,
        ])->assertOk()->json('html');
        $this->assertStringContainsString('Hello', $preview);

        $copyId = $this->spa()->actingAs($admin)->postJson("/api/v1/email-templates/{$id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.name', 'Copy of Welcome email v2')
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $this->spa()->actingAs($admin)->getJson("/api/v1/stores/{$store->id}/email-templates")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->spa()->actingAs($admin)->deleteJson("/api/v1/email-templates/{$copyId}")
            ->assertNoContent();
        $this->assertSoftDeleted('email_templates', ['id' => $copyId]);
    }

    public function test_rejects_invalid_and_product_blocks(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/email-templates", [
            'name' => 'Bad',
            'document' => [
                'version' => 1,
                'blocks' => [
                    ['id' => 'p1', 'type' => 'product', 'props' => []],
                ],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('document');
    }

    public function test_apply_template_to_campaign(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $template = EmailTemplate::factory()->create([
            'store_id' => $store->id,
            'created_by' => $admin->id,
            'name' => 'Promo',
            'html' => '<p>Promo body</p>',
            'document' => $this->sampleDocument(),
        ]);

        $campaignId = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/campaigns", [
            'name' => 'HTML campaign',
            'channel' => 'email',
            'subject' => 'Hi',
            'body' => 'plain',
            'audience_type' => 'all_eligible',
        ])->assertCreated()->json('data.id');

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaignId}/apply-template", [
            'email_template_id' => $template->id,
        ])->assertOk()
            ->assertJsonPath('data.content_format', 'html')
            ->assertJsonPath('data.email_template_id', $template->id)
            ->assertJsonPath('data.html_body', '<p>Promo body</p>');

        $campaign = Campaign::query()->findOrFail($campaignId);
        $this->assertSame('html', $campaign->content_format->value);
        $this->assertNotEmpty($campaign->content_document);

        $this->spa()->actingAs($admin)->putJson("/api/v1/campaigns/{$campaignId}", [
            'content_document' => $this->sampleDocument(),
        ])->assertOk()
            ->assertJsonPath('data.content_format', 'html')
            ->assertJsonPath('data.email_template_id', null);
    }

    public function test_cross_store_template_denied(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $this->storeIn($workspace);
        [$otherAdmin, $otherWorkspace] = $this->adminWithWorkspace('Other');
        $otherStore = $this->storeIn($otherWorkspace);

        $template = EmailTemplate::factory()->create([
            'store_id' => $otherStore->id,
            'created_by' => $otherAdmin->id,
        ]);

        $this->spa()->actingAs($admin)->getJson("/api/v1/email-templates/{$template->id}")
            ->assertForbidden();

        $outsider = User::factory()->create();
        $this->spa()->actingAs($outsider)->getJson("/api/v1/email-templates/{$template->id}")
            ->assertForbidden();
    }
}
