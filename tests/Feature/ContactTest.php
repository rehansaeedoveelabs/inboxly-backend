<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    public function test_create_list_show_update_delete_contact_scoped_to_store(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $id = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/contacts", [
            'email' => '  Jane@Example.COM ',
            'first_name' => 'Jane',
            'phone' => '+1 (555) 010-9999',
        ])->assertCreated()
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonPath('data.phone', '+15550109999')
            ->assertJsonPath('data.store_id', $store->id)
            ->assertJsonPath('data.consents.email.status', 'nonsubscribed')
            ->assertJsonPath('data.consents.sms.status', 'nonsubscribed')
            ->assertJsonPath('data.consents.push.status', 'nonsubscribed')
            ->json('data.id');

        $this->spa()->actingAs($admin)->getJson("/api/v1/stores/{$store->id}/contacts")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);

        $this->spa()->actingAs($admin)->getJson("/api/v1/contacts/{$id}")
            ->assertOk()->assertJsonPath('data.first_name', 'Jane');

        $this->spa()->actingAs($admin)->putJson("/api/v1/contacts/{$id}", ['first_name' => 'Janet', 'last_name' => 'Doe'])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Janet')
            ->assertJsonPath('data.email', 'jane@example.com');

        $this->spa()->actingAs($admin)->deleteJson("/api/v1/contacts/{$id}")->assertNoContent();

        $this->assertSoftDeleted('contacts', ['id' => $id]);
        $this->spa()->actingAs($admin)->getJson("/api/v1/contacts/{$id}")->assertNotFound();
        $this->spa()->actingAs($admin)->getJson("/api/v1/stores/{$store->id}/contacts")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_email_is_unique_per_store_but_not_across_stores(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $a = $this->storeIn($workspace);
        $b = $this->storeIn($workspace);
        $this->contactIn($a, ['email' => 'dup@example.com']);

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$a->id}/contacts", ['email' => 'DUP@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$b->id}/contacts", ['email' => 'dup@example.com'])
            ->assertCreated();
    }

    public function test_contact_needs_email_or_phone_and_emails_may_be_null_many_times(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/contacts", ['first_name' => 'Nobody'])
            ->assertUnprocessable();

        foreach (['+15550000001', '+15550000002'] as $phone) {
            $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/contacts", ['phone' => $phone, 'email' => ''])
                ->assertCreated()->assertJsonPath('data.email', null);
        }

        $id = Contact::where('phone', '+15550000001')->value('id');
        $this->spa()->actingAs($admin)->putJson("/api/v1/contacts/{$id}", ['phone' => null])
            ->assertUnprocessable();
        $this->spa()->actingAs($admin)->putJson("/api/v1/contacts/{$id}", ['phone' => '+15550000002'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_marketer_can_manage_audience_but_outsider_cannot(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $marketer = $this->marketerIn($workspace);
        $outsider = User::factory()->create();
        $contact = $this->contactIn($store);

        $this->spa()->actingAs($marketer)->postJson("/api/v1/stores/{$store->id}/contacts", ['email' => 'm@example.com'])
            ->assertCreated();
        $this->spa()->actingAs($marketer)->putJson("/api/v1/contacts/{$contact->id}", ['first_name' => 'X'])->assertOk();
        $this->spa()->actingAs($marketer)->deleteJson("/api/v1/contacts/{$contact->id}")->assertNoContent();

        $contact = $this->contactIn($store);
        $this->spa()->actingAs($outsider)->getJson("/api/v1/stores/{$store->id}/contacts")->assertForbidden();
        $this->spa()->actingAs($outsider)->postJson("/api/v1/stores/{$store->id}/contacts", ['email' => 'o@example.com'])->assertForbidden();
        $this->spa()->actingAs($outsider)->getJson("/api/v1/contacts/{$contact->id}")->assertForbidden();
        $this->spa()->actingAs($outsider)->putJson("/api/v1/contacts/{$contact->id}", ['first_name' => 'X'])->assertForbidden();
        $this->spa()->actingAs($outsider)->deleteJson("/api/v1/contacts/{$contact->id}")->assertForbidden();
        $this->spa()->actingAs($outsider)->putJson("/api/v1/contacts/{$contact->id}/consents", [
            'consents' => [['channel' => 'email', 'status' => 'subscribed']],
        ])->assertForbidden();

        $this->assertNull($contact->fresh()->deleted_at);
        $this->assertSame(0, $contact->consentEvents()->count());
    }

    public function test_cross_workspace_access_is_denied_even_with_valid_ids(): void
    {
        [$adminA, $workspaceA] = $this->adminWithWorkspace('A');
        [, $workspaceB] = $this->adminWithWorkspace('B');
        $storeB = $this->storeIn($workspaceB);
        $contactB = $this->contactIn($storeB);

        $this->spa()->actingAs($adminA)->getJson("/api/v1/stores/{$storeB->id}/contacts")->assertForbidden();
        $this->spa()->actingAs($adminA)->getJson("/api/v1/contacts/{$contactB->id}")->assertForbidden();

        // Same workspace, different store is fine; contacts never leak between stores in listings.
        $storeA1 = $this->storeIn($workspaceA);
        $storeA2 = $this->storeIn($workspaceA);
        $this->contactIn($storeA2);
        $this->spa()->actingAs($adminA)->getJson("/api/v1/stores/{$storeA1->id}/contacts")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        [, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $contact = $this->contactIn($store);

        $this->spa()->getJson("/api/v1/stores/{$store->id}/contacts")->assertUnauthorized();
        $this->spa()->postJson("/api/v1/stores/{$store->id}/contacts", ['email' => 'a@b.co'])->assertUnauthorized();
        $this->spa()->getJson("/api/v1/contacts/{$contact->id}")->assertUnauthorized();
        $this->spa()->deleteJson("/api/v1/contacts/{$contact->id}")->assertUnauthorized();
        $this->spa()->postJson("/api/v1/stores/{$store->id}/contact-imports", ['rows' => []])->assertUnauthorized();
    }

    public function test_cursor_pagination_walks_all_contacts_without_duplicates(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        foreach (range(1, 5) as $i) {
            Contact::factory()->create(['store_id' => $store->id, 'created_at' => now()->subMinutes($i)]);
        }
        // Same created_at as another row: id is the tiebreaker.
        Contact::factory()->count(2)->create(['store_id' => $store->id, 'created_at' => now()->subMinutes(5)]);

        $seen = [];
        $url = "/api/v1/stores/{$store->id}/contacts?limit=3";
        $pages = 0;

        do {
            $response = $this->spa()->actingAs($admin)->getJson($url)->assertOk();
            $this->assertLessThanOrEqual(3, count($response->json('data')));
            array_push($seen, ...array_column($response->json('data'), 'id'));
            $url = $response->json('links.next');
            $pages++;
        } while ($url !== null && $pages < 10);

        $this->assertSame(3, $pages);
        $this->assertCount(7, $seen);
        $this->assertCount(7, array_unique($seen));
    }

    public function test_list_filters_by_email_prefix_tag_and_consent(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $alice = $this->contactIn($store, ['email' => 'alice@example.com']);
        $this->contactIn($store, ['email' => 'bob@example.com']);
        $tag = $store->tags()->create(['name' => 'VIP', 'slug' => 'vip']);
        $alice->tags()->attach($tag->id, ['created_at' => now()]);
        $alice->consents()->where('channel', 'email')->update(['status' => 'subscribed']);

        $ids = fn (string $qs) => array_column(
            $this->spa()->actingAs($admin)->getJson("/api/v1/stores/{$store->id}/contacts?$qs")->assertOk()->json('data'), 'id'
        );

        $this->assertSame([$alice->id], $ids('q=ali'));
        $this->assertSame([$alice->id], $ids('q=alice@example.com'));
        $this->assertSame([$alice->id], $ids('tag=vip'));
        $this->assertSame([$alice->id], $ids('consent_email=subscribed'));
        $this->assertCount(1, $ids('consent_email=nonsubscribed'));
        $this->assertSame([], $ids('tag=vip&q=bob'));

        $this->spa()->actingAs($admin)->getJson("/api/v1/stores/{$store->id}/contacts?consent_email=bogus")
            ->assertUnprocessable();
    }
}
