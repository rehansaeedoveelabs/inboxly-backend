<?php

namespace Tests\Feature;

use App\Actions\RestoreContact;
use App\Actions\UpsertContact;
use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Enums\ShopifyCustomerIdentityLinkSource;
use App\Enums\ShopifyCustomerIdentityStatus;
use App\Enums\ShopifyIdentityConflictStatus;
use App\Enums\ShopifyIdentityConflictType;
use App\Models\ConsentEvent;
use App\Models\Contact;
use App\Models\ContactConsent;
use App\Models\ShopifyCustomerIdentity;
use App\Models\ShopifyIdentityConflict;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ShopifyCustomerIdentitySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_phpunit_targets_inboxly_testing_database(): void
    {
        $this->assertSame('inboxly_testing', config('database.connections.mysql.database'));
        $this->assertSame('inboxly_testing', DB::selectOne('select database() as d')->d);
        $this->assertNotSame('inboxly', DB::selectOne('select database() as d')->d);
    }

    public function test_identity_and_conflict_tables_have_required_columns_and_indexes(): void
    {
        $identityColumns = collect(Schema::getColumnListing('shopify_customer_identities'));
        foreach ([
            'id', 'store_id', 'shopify_customer_gid', 'contact_id', 'status', 'link_source',
            'linked_at', 'unlinked_at', 'shopify_deleted_at', 'shopify_updated_at',
            'last_synced_at', 'last_seen_at', 'sync_error_code', 'metadata',
            'created_at', 'updated_at',
        ] as $column) {
            $this->assertTrue($identityColumns->contains($column), "missing identity column {$column}");
        }

        $conflictColumns = collect(Schema::getColumnListing('shopify_identity_conflicts'));
        foreach ([
            'id', 'shopify_customer_identity_id', 'candidate_contact_id', 'conflict_type', 'status',
            'match_signals', 'resolved_at', 'resolved_by_user_id', 'resolution_code', 'resolution_meta',
            'created_at', 'updated_at',
        ] as $column) {
            $this->assertTrue($conflictColumns->contains($column), "missing conflict column {$column}");
        }

        $identityIndexes = collect(DB::select('show index from shopify_customer_identities'))
            ->pluck('Key_name')
            ->unique()
            ->values();
        $this->assertTrue($identityIndexes->contains('shopify_cust_identities_store_gid_uidx'));
        $this->assertTrue($identityIndexes->contains('shopify_cust_identities_store_contact_uidx'));

        $conflictIndexes = collect(DB::select('show index from shopify_identity_conflicts'))
            ->pluck('Key_name')
            ->unique()
            ->values();
        $this->assertTrue($conflictIndexes->contains('shopify_id_conflicts_identity_status_idx'));
        $this->assertTrue($conflictIndexes->contains('shopify_id_conflicts_status_created_idx'));
    }

    public function test_identity_and_conflict_use_ulid_primary_keys_and_relationships(): void
    {
        $store = Store::factory()->create();
        $contact = Contact::factory()->create(['store_id' => $store->id]);
        $resolver = User::factory()->create();
        $identity = ShopifyCustomerIdentity::factory()->linkedTo($contact)->create();
        $conflict = ShopifyIdentityConflict::factory()->create([
            'shopify_customer_identity_id' => $identity->id,
            'candidate_contact_id' => $contact->id,
            'conflict_type' => ShopifyIdentityConflictType::EmailMatch,
            'status' => ShopifyIdentityConflictStatus::ResolvedLinked,
            'resolved_by_user_id' => $resolver->id,
            'resolved_at' => now(),
            'resolution_code' => 'linked',
        ]);

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $identity->id);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $conflict->id);

        $this->assertTrue($identity->store->is($store));
        $this->assertTrue($identity->contact->is($contact));
        $this->assertTrue($conflict->identity->is($identity));
        $this->assertTrue($conflict->candidateContact->is($contact));
        $this->assertTrue($conflict->resolvedByUser->is($resolver));
        $this->assertTrue($store->shopifyCustomerIdentities->contains($identity));
        $this->assertTrue($contact->shopifyCustomerIdentities->contains($identity));
        $this->assertTrue($identity->conflicts->contains($conflict));

        $this->assertSame(ShopifyCustomerIdentityStatus::Tracked, $identity->fresh()->status);
        $this->assertSame(ShopifyCustomerIdentityLinkSource::ManualAdmin, $identity->fresh()->link_source);
        $this->assertSame(ShopifyIdentityConflictType::EmailMatch, $conflict->fresh()->conflict_type);
        $this->assertSame(ShopifyIdentityConflictStatus::ResolvedLinked, $conflict->fresh()->status);
    }

    public function test_identity_and_conflict_status_enums_persist_all_cases(): void
    {
        foreach (ShopifyCustomerIdentityStatus::cases() as $status) {
            $identity = ShopifyCustomerIdentity::factory()->create([
                'status' => $status,
                'shopify_customer_gid' => 'gid://shopify/Customer/status-'.$status->value,
            ]);
            $this->assertSame($status, $identity->fresh()->status);
        }

        $this->assertSame(
            ['tracked', 'unlinked', 'shopify_deleted'],
            array_column(ShopifyCustomerIdentityStatus::cases(), 'value'),
        );

        $identity = ShopifyCustomerIdentity::factory()->create();
        foreach (ShopifyIdentityConflictStatus::cases() as $status) {
            $conflict = ShopifyIdentityConflict::factory()->create([
                'shopify_customer_identity_id' => $identity->id,
                'status' => $status,
                'conflict_type' => ShopifyIdentityConflictType::PhoneMatch,
            ]);
            $this->assertSame($status, $conflict->fresh()->status);
        }

        $this->assertSame(
            ['open', 'resolved_linked', 'resolved_dismissed', 'resolved_ignored'],
            array_column(ShopifyIdentityConflictStatus::cases(), 'value'),
        );

        foreach (ShopifyIdentityConflictType::cases() as $type) {
            $conflict = ShopifyIdentityConflict::factory()->create([
                'shopify_customer_identity_id' => $identity->id,
                'conflict_type' => $type,
                'status' => ShopifyIdentityConflictStatus::Open,
            ]);
            $this->assertSame($type, $conflict->fresh()->conflict_type);
        }
    }

    public function test_duplicate_shopify_gid_rejected_within_one_store(): void
    {
        $store = Store::factory()->create();
        $gid = 'gid://shopify/Customer/1111111111';

        ShopifyCustomerIdentity::factory()->create([
            'store_id' => $store->id,
            'shopify_customer_gid' => $gid,
        ]);

        $this->expectException(QueryException::class);

        ShopifyCustomerIdentity::factory()->create([
            'store_id' => $store->id,
            'shopify_customer_gid' => $gid,
        ]);
    }

    public function test_same_gid_allowed_in_different_stores(): void
    {
        $gid = 'gid://shopify/Customer/2222222222';
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();

        ShopifyCustomerIdentity::factory()->create([
            'store_id' => $storeA->id,
            'shopify_customer_gid' => $gid,
        ]);

        $other = ShopifyCustomerIdentity::factory()->create([
            'store_id' => $storeB->id,
            'shopify_customer_gid' => $gid,
        ]);

        $this->assertSame($gid, $other->shopify_customer_gid);
        $this->assertSame(
            2,
            ShopifyCustomerIdentity::query()->where('shopify_customer_gid', $gid)->count(),
        );
    }

    public function test_multiple_null_contact_ids_allowed_in_same_store(): void
    {
        $store = Store::factory()->create();

        ShopifyCustomerIdentity::factory()->create([
            'store_id' => $store->id,
            'contact_id' => null,
            'shopify_customer_gid' => 'gid://shopify/Customer/3001',
        ]);

        $second = ShopifyCustomerIdentity::factory()->create([
            'store_id' => $store->id,
            'contact_id' => null,
            'shopify_customer_gid' => 'gid://shopify/Customer/3002',
        ]);

        $this->assertNull($second->contact_id);
        $this->assertSame(
            2,
            ShopifyCustomerIdentity::query()
                ->where('store_id', $store->id)
                ->whereNull('contact_id')
                ->count(),
        );
    }

    public function test_duplicate_non_null_contact_id_rejected_within_same_store(): void
    {
        $store = Store::factory()->create();
        $contact = Contact::factory()->create(['store_id' => $store->id]);

        ShopifyCustomerIdentity::factory()->linkedTo($contact)->create([
            'shopify_customer_gid' => 'gid://shopify/Customer/4001',
        ]);

        $this->expectException(QueryException::class);

        ShopifyCustomerIdentity::factory()->create([
            'store_id' => $store->id,
            'contact_id' => $contact->id,
            'shopify_customer_gid' => 'gid://shopify/Customer/4002',
            'status' => ShopifyCustomerIdentityStatus::Tracked,
        ]);
    }

    public function test_contact_id_fk_does_not_enforce_same_store_ownership(): void
    {
        // Documented M2 safeguard: schema allows a cross-store contact_id;
        // future linking services must validate ownership transactionally.
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $contactOnB = Contact::factory()->create(['store_id' => $storeB->id]);

        $identity = ShopifyCustomerIdentity::factory()->create([
            'store_id' => $storeA->id,
            'contact_id' => $contactOnB->id,
            'shopify_customer_gid' => 'gid://shopify/Customer/cross-store-1',
            'status' => ShopifyCustomerIdentityStatus::Tracked,
        ]);

        $this->assertSame($storeA->id, $identity->fresh()->store_id);
        $this->assertSame($contactOnB->id, $identity->fresh()->contact_id);
        $this->assertNotSame($identity->store_id, $contactOnB->store_id);
    }

    public function test_contact_hard_delete_nulls_identity_contact_id(): void
    {
        $store = Store::factory()->create();
        $contact = Contact::factory()->create(['store_id' => $store->id]);
        $identity = ShopifyCustomerIdentity::factory()->linkedTo($contact)->create();
        $conflict = ShopifyIdentityConflict::factory()->create([
            'shopify_customer_identity_id' => $identity->id,
            'candidate_contact_id' => $contact->id,
        ]);

        $contact->forceDelete();

        $fresh = $identity->fresh();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->contact_id);
        $this->assertSame(ShopifyCustomerIdentityStatus::Tracked, $fresh->status);
        $this->assertNull($conflict->fresh()->candidate_contact_id);
    }

    public function test_contact_soft_delete_unlinks_identity_and_preserves_consent_and_conflicts(): void
    {
        $store = Store::factory()->create();
        $contact = Contact::factory()->create(['store_id' => $store->id]);

        foreach (ConsentChannel::cases() as $channel) {
            ContactConsent::factory()->create([
                'contact_id' => $contact->id,
                'channel' => $channel,
                'status' => ConsentStatus::Subscribed,
            ]);
        }

        $event = ConsentEvent::factory()->create([
            'contact_id' => $contact->id,
            'channel' => ConsentChannel::Email,
            'status' => ConsentStatus::Subscribed,
        ]);

        $consentSnapshot = ContactConsent::query()
            ->where('contact_id', $contact->id)
            ->orderBy('channel')
            ->get(['id', 'channel', 'status', 'updated_at'])
            ->map(fn (ContactConsent $row) => [
                'id' => $row->id,
                'channel' => $row->channel->value,
                'status' => $row->status->value,
                'updated_at' => optional($row->updated_at)?->toJSON(),
            ])
            ->all();

        $identity = ShopifyCustomerIdentity::factory()->linkedTo($contact)->create([
            'shopify_customer_gid' => 'gid://shopify/Customer/5001',
        ]);
        $conflict = ShopifyIdentityConflict::factory()->create([
            'shopify_customer_identity_id' => $identity->id,
            'candidate_contact_id' => $contact->id,
            'status' => ShopifyIdentityConflictStatus::Open,
            'conflict_type' => ShopifyIdentityConflictType::EmailMatch,
            'match_signals' => ['email_match' => true],
        ]);

        $contact->delete();

        $freshIdentity = $identity->fresh();
        $this->assertNotNull($freshIdentity);
        $this->assertNull($freshIdentity->contact_id);
        $this->assertSame(ShopifyCustomerIdentityStatus::Unlinked, $freshIdentity->status);
        $this->assertNotNull($freshIdentity->unlinked_at);
        $this->assertSame($identity->shopify_customer_gid, $freshIdentity->shopify_customer_gid);

        $freshConflict = $conflict->fresh();
        $this->assertNotNull($freshConflict);
        $this->assertSame($identity->id, $freshConflict->shopify_customer_identity_id);
        $this->assertSame($contact->id, $freshConflict->candidate_contact_id);
        $this->assertSame(ShopifyIdentityConflictStatus::Open, $freshConflict->status);
        $this->assertSame(['email_match' => true], $freshConflict->match_signals);
        $this->assertNotNull($contact->fresh()->deleted_at);

        $consentAfter = ContactConsent::query()
            ->where('contact_id', $contact->id)
            ->orderBy('channel')
            ->get(['id', 'channel', 'status', 'updated_at'])
            ->map(fn (ContactConsent $row) => [
                'id' => $row->id,
                'channel' => $row->channel->value,
                'status' => $row->status->value,
                'updated_at' => optional($row->updated_at)?->toJSON(),
            ])
            ->all();

        $this->assertSame($consentSnapshot, $consentAfter);
        $this->assertDatabaseHas('consent_events', [
            'id' => $event->id,
            'contact_id' => $contact->id,
            'status' => ConsentStatus::Subscribed->value,
        ]);
        $this->assertSame(1, ConsentEvent::query()->where('contact_id', $contact->id)->count());
    }

    public function test_soft_delete_rolls_back_when_identity_unlink_save_fails(): void
    {
        $store = Store::factory()->create();
        $contact = Contact::factory()->create(['store_id' => $store->id]);

        foreach (ConsentChannel::cases() as $channel) {
            ContactConsent::factory()->create([
                'contact_id' => $contact->id,
                'channel' => $channel,
                'status' => ConsentStatus::Subscribed,
            ]);
        }

        $event = ConsentEvent::factory()->create([
            'contact_id' => $contact->id,
            'channel' => ConsentChannel::Email,
            'status' => ConsentStatus::Subscribed,
        ]);

        $consentSnapshot = ContactConsent::query()
            ->where('contact_id', $contact->id)
            ->orderBy('channel')
            ->get(['id', 'channel', 'status', 'updated_at'])
            ->map(fn (ContactConsent $row) => [
                'id' => $row->id,
                'channel' => $row->channel->value,
                'status' => $row->status->value,
                'updated_at' => optional($row->updated_at)?->toJSON(),
            ])
            ->all();

        $identity = ShopifyCustomerIdentity::factory()->linkedTo($contact)->create([
            'shopify_customer_gid' => 'gid://shopify/Customer/rollback-1',
        ]);

        $originalStatus = $identity->status;
        $originalContactId = $identity->contact_id;

        ShopifyCustomerIdentity::saving(function () {
            throw new RuntimeException('simulated unlink failure');
        });

        try {
            try {
                $contact->delete();
                $this->fail('Expected RuntimeException when identity unlink save fails.');
            } catch (RuntimeException $e) {
                $this->assertSame('simulated unlink failure', $e->getMessage());
            }

            $freshContact = $contact->fresh();
            $this->assertNotNull($freshContact);
            $this->assertNull($freshContact->deleted_at);
            $this->assertFalse($freshContact->trashed());

            $freshIdentity = $identity->fresh();
            $this->assertNotNull($freshIdentity);
            $this->assertSame($originalContactId, $freshIdentity->contact_id);
            $this->assertSame($originalStatus, $freshIdentity->status);
            $this->assertNull($freshIdentity->unlinked_at);

            $consentAfter = ContactConsent::query()
                ->where('contact_id', $contact->id)
                ->orderBy('channel')
                ->get(['id', 'channel', 'status', 'updated_at'])
                ->map(fn (ContactConsent $row) => [
                    'id' => $row->id,
                    'channel' => $row->channel->value,
                    'status' => $row->status->value,
                    'updated_at' => optional($row->updated_at)?->toJSON(),
                ])
                ->all();

            $this->assertSame($consentSnapshot, $consentAfter);
            $this->assertDatabaseHas('consent_events', [
                'id' => $event->id,
                'contact_id' => $contact->id,
                'status' => ConsentStatus::Subscribed->value,
            ]);
            $this->assertSame(1, ConsentEvent::query()->where('contact_id', $contact->id)->count());
        } finally {
            ShopifyCustomerIdentity::flushEventListeners();
        }
    }

    public function test_soft_delete_without_linked_identity_still_succeeds(): void
    {
        $contact = Contact::factory()->create();
        ShopifyCustomerIdentity::factory()->create([
            'store_id' => $contact->store_id,
            'contact_id' => null,
            'shopify_customer_gid' => 'gid://shopify/Customer/orphan-1',
        ]);

        $contact->delete();

        $this->assertNotNull($contact->fresh()->deleted_at);
        $this->assertSame(1, ShopifyCustomerIdentity::query()->where('store_id', $contact->store_id)->count());
    }

    public function test_contact_restore_action_does_not_relink_shopify_identity(): void
    {
        $store = Store::factory()->create();
        $contact = Contact::factory()->create(['store_id' => $store->id]);
        $identity = ShopifyCustomerIdentity::factory()->linkedTo($contact)->create([
            'shopify_customer_gid' => 'gid://shopify/Customer/5501',
        ]);

        $contact->delete();
        $this->assertNull($identity->fresh()->contact_id);

        $restored = app(RestoreContact::class)->handle($contact->fresh());

        $fresh = $identity->fresh();
        $this->assertNull($fresh->contact_id);
        $this->assertSame(ShopifyCustomerIdentityStatus::Unlinked, $fresh->status);
        $this->assertNull($restored->deleted_at);
        $this->assertNotNull($restored->consent_review_required_at);
    }

    public function test_upsert_restore_does_not_relink_shopify_identity(): void
    {
        $store = Store::factory()->create();
        $contact = Contact::factory()->create([
            'store_id' => $store->id,
            'email' => 'restore-me@example.com',
        ]);
        $identity = ShopifyCustomerIdentity::factory()->linkedTo($contact)->create([
            'shopify_customer_gid' => 'gid://shopify/Customer/5601',
        ]);

        $contact->delete();

        $upserted = app(UpsertContact::class)->handle($store, [
            'email' => 'restore-me@example.com',
            'first_name' => 'Restored',
        ]);

        $this->assertTrue($upserted->wasRestored);
        $this->assertNull($upserted->deleted_at);
        $this->assertNull($identity->fresh()->contact_id);
        $this->assertSame(ShopifyCustomerIdentityStatus::Unlinked, $identity->fresh()->status);
    }

    public function test_store_hard_delete_cascades_identities_and_conflicts(): void
    {
        $store = Store::factory()->create();
        $identity = ShopifyCustomerIdentity::factory()->create(['store_id' => $store->id]);
        $conflict = ShopifyIdentityConflict::factory()->create([
            'shopify_customer_identity_id' => $identity->id,
        ]);

        $store->forceDelete();

        $this->assertDatabaseMissing('shopify_customer_identities', ['id' => $identity->id]);
        $this->assertDatabaseMissing('shopify_identity_conflicts', ['id' => $conflict->id]);
    }

    public function test_store_soft_delete_keeps_identities(): void
    {
        $store = Store::factory()->create();
        $identity = ShopifyCustomerIdentity::factory()->create(['store_id' => $store->id]);

        $store->delete();

        $this->assertNotNull($identity->fresh());
        $this->assertNotNull($store->fresh()->deleted_at);
    }

    public function test_identity_delete_cascades_conflicts(): void
    {
        $identity = ShopifyCustomerIdentity::factory()->create();
        $conflict = ShopifyIdentityConflict::factory()->create([
            'shopify_customer_identity_id' => $identity->id,
        ]);

        $identity->delete();

        $this->assertDatabaseMissing('shopify_customer_identities', ['id' => $identity->id]);
        $this->assertDatabaseMissing('shopify_identity_conflicts', ['id' => $conflict->id]);
    }

    public function test_resolved_by_user_nulls_when_user_deleted(): void
    {
        $user = User::factory()->create();
        $conflict = ShopifyIdentityConflict::factory()->create([
            'status' => ShopifyIdentityConflictStatus::ResolvedDismissed,
            'resolved_by_user_id' => $user->id,
            'resolved_at' => now(),
            'resolution_code' => 'dismissed',
        ]);

        $user->delete();

        $this->assertNull($conflict->fresh()->resolved_by_user_id);
        $this->assertSame(ShopifyIdentityConflictStatus::ResolvedDismissed, $conflict->fresh()->status);
    }

    public function test_schema_has_no_pii_snapshot_or_workspace_columns(): void
    {
        $identityColumns = collect(Schema::getColumnListing('shopify_customer_identities'));
        $conflictColumns = collect(Schema::getColumnListing('shopify_identity_conflicts'));

        foreach ([
            'email', 'phone', 'first_name', 'last_name', 'display_name', 'address',
            'workspace_id', 'shopify_customer_legacy_id', 'shopify_customer_id',
        ] as $forbidden) {
            $this->assertFalse($identityColumns->contains($forbidden), "identity has {$forbidden}");
            $this->assertFalse($conflictColumns->contains($forbidden), "conflict has {$forbidden}");
        }

        $this->assertFalse($conflictColumns->contains('shopify_customer_gid'));
        $this->assertFalse($conflictColumns->contains('store_id'));
        $this->assertTrue($identityColumns->contains('shopify_customer_gid'));
        $this->assertTrue($identityColumns->contains('store_id'));
        $this->assertTrue($identityColumns->contains('metadata'));
        $this->assertTrue($conflictColumns->contains('match_signals'));
        $this->assertTrue($conflictColumns->contains('resolution_meta'));
    }

    public function test_json_metadata_fields_round_trip_without_requiring_pii(): void
    {
        $identity = ShopifyCustomerIdentity::factory()->create([
            'metadata' => ['flags' => ['sync_partial' => true], 'counts' => ['events' => 2]],
            'shopify_updated_at' => now()->subMinute(),
            'last_synced_at' => now()->subSeconds(30),
            'last_seen_at' => now(),
            'sync_error_code' => 'rate_limited',
        ]);
        $conflict = ShopifyIdentityConflict::factory()->create([
            'shopify_customer_identity_id' => $identity->id,
            'match_signals' => ['email_match' => true, 'candidate_count' => 1],
            'resolution_meta' => ['reason_code' => 'admin_choice'],
            'status' => ShopifyIdentityConflictStatus::ResolvedIgnored,
            'resolution_code' => 'ignored',
            'resolved_at' => now(),
        ]);

        $freshIdentity = $identity->fresh();
        $this->assertSame(
            ['flags' => ['sync_partial' => true], 'counts' => ['events' => 2]],
            $freshIdentity->metadata,
        );
        $this->assertSame('rate_limited', $freshIdentity->sync_error_code);
        $this->assertNotNull($freshIdentity->shopify_updated_at);
        $this->assertNotNull($freshIdentity->last_synced_at);
        $this->assertNotNull($freshIdentity->last_seen_at);
        $this->assertSame(
            ['email_match' => true, 'candidate_count' => 1],
            $conflict->fresh()->match_signals,
        );
        $this->assertSame(['reason_code' => 'admin_choice'], $conflict->fresh()->resolution_meta);
    }
}
