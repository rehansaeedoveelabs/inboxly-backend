<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\EmailTemplate;
use App\Models\ShopifyProduct;
use App\Models\Store;
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

    public function test_rejects_unknown_block_types(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/email-templates", [
            'name' => 'Bad',
            'document' => [
                'version' => 1,
                'blocks' => [
                    ['id' => 'p1', 'type' => 'carousel', 'props' => []],
                ],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('document');
    }

    public function test_product_block_persists_and_renders_featured_image(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->withShopifyCredentials('product-email.myshopify.com')->create([
            'workspace_id' => $workspace->id,
            'domain' => 'shop.example.com',
        ]);

        $product = ShopifyProduct::factory()->create([
            'store_id' => $store->id,
            'title' => 'Honey Jar',
            'handle' => 'honey-jar',
            'metadata' => [
                'featured_image_url' => 'https://cdn.example/honey.jpg',
                'variant_count' => 1,
                'price_min' => '12.00',
                'price_max' => '12.00',
                'currency_code' => 'USD',
            ],
        ]);

        $document = [
            'version' => 1,
            'settings' => ['width' => 600, 'background' => '#ffffff'],
            'blocks' => [
                [
                    'id' => 'prod_1',
                    'type' => 'product',
                    'props' => [
                        'shopify_product_id' => $product->id,
                        'title' => 'Honey Jar',
                        'image_url' => 'https://cdn.example/honey.jpg',
                        'product_url' => 'https://shop.example.com/products/honey-jar',
                        'price' => '12.00',
                        'currency_code' => 'USD',
                        'button_label' => 'Shop now',
                        'show_title' => true,
                        'show_price' => true,
                        'show_button' => true,
                    ],
                ],
            ],
        ];

        $id = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/email-templates", [
            'name' => 'Product spotlight',
            'document' => $document,
        ])->assertCreated()
            ->assertJsonPath('data.document.blocks.0.type', 'product')
            ->assertJsonPath('data.document.blocks.0.props.shopify_product_id', $product->id)
            ->json('data.id');

        $html = $this->spa()->actingAs($admin)->getJson("/api/v1/email-templates/{$id}")
            ->assertOk()
            ->json('data.html');

        $this->assertIsString($html);
        $this->assertStringContainsString('Honey Jar', $html);
        $this->assertStringContainsString('https://cdn.example/honey.jpg', $html);
        $this->assertStringContainsString('https://shop.example.com/products/honey-jar', $html);
        $this->assertStringContainsString('USD 12.00', $html);
        $this->assertStringContainsString('Shop now', $html);

        $preview = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/email-templates/preview", [
            'document' => [
                'version' => 1,
                'blocks' => [
                    [
                        'id' => 'prod_empty',
                        'type' => 'product',
                        'props' => [
                            'shopify_product_id' => $product->id,
                            'title' => '',
                            'image_url' => null,
                            'product_url' => null,
                            'button_label' => 'Buy',
                            'show_title' => true,
                            'show_button' => true,
                        ],
                    ],
                ],
            ],
        ])->assertOk()->json('html');

        $this->assertStringContainsString('Honey Jar', $preview);
        $this->assertStringContainsString('https://cdn.example/honey.jpg', $preview);
        $this->assertStringContainsString('/products/honey-jar', $preview);
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
            'subject' => null,
            'body' => 'plain',
            'audience_type' => 'all_eligible',
        ])->assertCreated()->json('data.id');

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaignId}/apply-template", [
            'email_template_id' => $template->id,
        ])->assertOk()
            ->assertJsonPath('data.content_format', 'html')
            ->assertJsonPath('data.email_template_id', $template->id)
            ->assertJsonPath('data.html_body', '<p>Promo body</p>')
            ->assertJsonPath('data.subject', 'Promo');

        $campaign = Campaign::query()->findOrFail($campaignId);
        $this->assertSame('html', $campaign->content_format->value);
        $this->assertNotEmpty($campaign->content_document);
        $this->assertSame('Promo', $campaign->subject);

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
