<?php

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class TagTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    public function test_create_list_update_delete_tags(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $id = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/tags", ['name' => 'VIP Customers', 'color' => '#ff0000'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'vip-customers')
            ->assertJsonPath('data.color', '#ff0000')
            ->json('data.id');

        $this->spa()->actingAs($admin)->getJson("/api/v1/stores/{$store->id}/tags")
            ->assertOk()->assertJsonCount(1, 'data');

        // Renaming keeps the slug stable (segments reference slugs).
        $this->spa()->actingAs($admin)->putJson("/api/v1/tags/{$id}", ['name' => 'Gold'])
            ->assertOk()->assertJsonPath('data.name', 'Gold')->assertJsonPath('data.slug', 'vip-customers');

        $this->spa()->actingAs($admin)->deleteJson("/api/v1/tags/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('tags', ['id' => $id]);
    }

    public function test_slug_is_unique_per_store(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $a = $this->storeIn($workspace);
        $b = $this->storeIn($workspace);

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$a->id}/tags", ['name' => 'VIP'])->assertCreated();
        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$a->id}/tags", ['name' => 'vip'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$b->id}/tags", ['name' => 'vip'])->assertCreated();
        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$a->id}/tags", ['name' => '!!!'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_attach_and_detach_tags_on_contact(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $contact = $this->contactIn($store);
        $vip = Tag::factory()->create(['store_id' => $store->id, 'name' => 'VIP', 'slug' => 'vip']);
        $new = Tag::factory()->create(['store_id' => $store->id, 'name' => 'New', 'slug' => 'new']);

        $this->spa()->actingAs($admin)->postJson("/api/v1/contacts/{$contact->id}/tags", ['tag_ids' => [$vip->id, $new->id]])
            ->assertOk()->assertJsonCount(2, 'data.tags');

        // Idempotent.
        $this->spa()->actingAs($admin)->postJson("/api/v1/contacts/{$contact->id}/tags", ['tag_ids' => [$vip->id]])
            ->assertOk()->assertJsonCount(2, 'data.tags');
        $this->assertSame(2, $contact->tags()->count());

        $this->spa()->actingAs($admin)->deleteJson("/api/v1/contacts/{$contact->id}/tags/{$vip->id}")
            ->assertOk()->assertJsonCount(1, 'data.tags');
        $this->assertSame(['new'], $contact->tags()->pluck('slug')->all());
    }

    public function test_tags_from_other_stores_cannot_be_attached_or_detached(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $other = $this->storeIn($workspace);
        $contact = $this->contactIn($store);
        $foreign = Tag::factory()->create(['store_id' => $other->id]);

        $this->spa()->actingAs($admin)->postJson("/api/v1/contacts/{$contact->id}/tags", ['tag_ids' => [$foreign->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('tag_ids.0');
        $this->spa()->actingAs($admin)->deleteJson("/api/v1/contacts/{$contact->id}/tags/{$foreign->id}")
            ->assertNotFound();
        $this->assertSame(0, $contact->tags()->count());
    }

    public function test_outsider_cannot_touch_tags(): void
    {
        [, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $tag = Tag::factory()->create(['store_id' => $store->id]);
        $contact = $this->contactIn($store);
        $outsider = User::factory()->create();

        $this->spa()->actingAs($outsider)->getJson("/api/v1/stores/{$store->id}/tags")->assertForbidden();
        $this->spa()->actingAs($outsider)->postJson("/api/v1/stores/{$store->id}/tags", ['name' => 'x'])->assertForbidden();
        $this->spa()->actingAs($outsider)->putJson("/api/v1/tags/{$tag->id}", ['name' => 'y'])->assertForbidden();
        $this->spa()->actingAs($outsider)->deleteJson("/api/v1/tags/{$tag->id}")->assertForbidden();
        $this->spa()->actingAs($outsider)->postJson("/api/v1/contacts/{$contact->id}/tags", ['tag_ids' => [$tag->id]])->assertForbidden();
        $this->spa()->actingAs($outsider)->deleteJson("/api/v1/contacts/{$contact->id}/tags/{$tag->id}")->assertForbidden();

        $marketer = $this->marketerIn($workspace);
        $this->spa()->actingAs($marketer)->postJson("/api/v1/stores/{$store->id}/tags", ['name' => 'by marketer'])->assertCreated();
    }
}
