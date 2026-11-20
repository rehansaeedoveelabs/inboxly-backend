<?php

namespace Tests\Feature;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Enums\FormStatus;
use App\Enums\FormType;
use App\Models\Contact;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Support\Forms\FormDefinitionSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class PublicFormTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    public function test_public_show_embed_and_submit_creates_contact_and_consent(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $form = Form::factory()->create([
            'store_id' => $store->id,
            'created_by' => $admin->id,
            'type' => FormType::Popup,
            'status' => FormStatus::Enabled,
            'definition' => FormDefinitionSchema::defaultDefinition(),
            'public_token' => 'tok_public_form_test_001',
        ]);

        $this->getJson("/api/v1/public/forms/{$form->public_token}")
            ->assertOk()
            ->assertJsonPath('data.token', $form->public_token)
            ->assertJsonPath('data.name', $form->name);

        $this->get("/api/v1/public/forms/{$form->public_token}/embed")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('Subscribe', false);

        $this->postJson("/api/v1/public/forms/{$form->public_token}/submissions", [
            'fields' => [
                'f_email' => 'signup@example.com',
                'f_consent' => true,
            ],
        ])->assertCreated()
            ->assertJsonPath('data.ok', true);

        $contact = Contact::query()->where('store_id', $store->id)->where('email', 'signup@example.com')->first();
        $this->assertNotNull($contact);
        $this->assertSame('form', $contact->source);
        $this->assertSame(
            ConsentStatus::Subscribed,
            $contact->consents()->where('channel', ConsentChannel::Email)->first()?->status
        );
        $this->assertSame(1, FormSubmission::query()->where('form_id', $form->id)->count());
        $this->assertSame(1, $form->fresh()->submissions_count);
    }

    public function test_draft_form_rejects_public_access(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $form = Form::factory()->create([
            'store_id' => $store->id,
            'created_by' => $admin->id,
            'status' => FormStatus::Draft,
            'public_token' => 'tok_draft_only',
            'definition' => FormDefinitionSchema::defaultDefinition(),
        ]);

        $this->getJson("/api/v1/public/forms/{$form->public_token}")->assertNotFound();
        $this->postJson("/api/v1/public/forms/{$form->public_token}/submissions", [
            'fields' => ['f_email' => 'a@b.com', 'f_consent' => true],
        ])->assertNotFound();
    }

    public function test_embed_renders_image_from_external_url(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $definition = FormDefinitionSchema::defaultDefinition();
        $definition['fields'] = [
            [
                'id' => 'f_img',
                'type' => 'image',
                'label' => 'Hero',
                'required' => false,
                'props' => [
                    'asset_id' => null,
                    'src' => 'https://cdn.example/hero.png',
                    'alt' => 'Hero image',
                ],
            ],
            [
                'id' => 'f_email',
                'type' => 'email',
                'label' => 'Email',
                'required' => true,
            ],
            [
                'id' => 'f_consent',
                'type' => 'consent',
                'label' => 'I agree',
                'required' => true,
            ],
        ];

        $form = Form::factory()->create([
            'store_id' => $store->id,
            'created_by' => $admin->id,
            'status' => FormStatus::Enabled,
            'public_token' => 'tok_image_embed',
            'definition' => $definition,
        ]);

        $this->get("/api/v1/public/forms/{$form->public_token}/embed")
            ->assertOk()
            ->assertSee('https://cdn.example/hero.png', false)
            ->assertSee('Hero image', false)
            ->assertDontSee('name="f_img"', false);
    }

    public function test_required_consent_is_enforced(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $form = Form::factory()->create([
            'store_id' => $store->id,
            'created_by' => $admin->id,
            'status' => FormStatus::Enabled,
            'public_token' => 'tok_consent_required',
            'definition' => FormDefinitionSchema::defaultDefinition(),
        ]);

        $this->postJson("/api/v1/public/forms/{$form->public_token}/submissions", [
            'fields' => [
                'f_email' => 'noconsent@example.com',
                'f_consent' => false,
            ],
        ])->assertUnprocessable();
    }
}
