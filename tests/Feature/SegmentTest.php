<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\User;
use App\Support\Segments\SegmentQueryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class SegmentTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    private function rules(array ...$conditions): array
    {
        return ['operator' => 'and', 'conditions' => $conditions];
    }

    public function test_create_show_update_delete_segment(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $rules = $this->rules(['field' => 'email', 'op' => 'contains', 'value' => '@gmail.com']);

        $id = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/segments", [
            'name' => 'Gmail users', 'rules' => $rules + ['junk' => 'dropped'],
        ])->assertCreated()
            ->assertJsonPath('data.rules.conditions.0.field', 'email')
            ->assertJsonMissingPath('data.rules.junk')
            ->json('data.id');

        $this->spa()->actingAs($admin)->getJson("/api/v1/stores/{$store->id}/segments")->assertOk()->assertJsonCount(1, 'data');
        $this->spa()->actingAs($admin)->getJson("/api/v1/segments/{$id}")->assertOk()->assertJsonPath('data.name', 'Gmail users');

        $this->spa()->actingAs($admin)->putJson("/api/v1/segments/{$id}", [
            'name' => 'Renamed',
            'rules' => ['operator' => 'or', 'conditions' => [['field' => 'phone', 'op' => 'is_not_null']]],
        ])->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.rules.operator', 'or');

        $this->spa()->actingAs($admin)->deleteJson("/api/v1/segments/{$id}")->assertNoContent();
        $this->assertSoftDeleted('segments', ['id' => $id]);
    }

    #[DataProvider('invalidRules')]
    public function test_invalid_rules_are_rejected_on_write(array $rules): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/segments", ['name' => 'Bad', 'rules' => $rules])
            ->assertUnprocessable()->assertJsonValidationErrors('rules');

        $this->assertSame(0, Segment::count());
    }

    public static function invalidRules(): array
    {
        return [
            'bad operator' => [['operator' => 'xor', 'conditions' => []]],
            'conditions not a list' => [['operator' => 'and', 'conditions' => ['a' => 1]]],
            'unknown field' => [['operator' => 'and', 'conditions' => [['field' => 'password', 'op' => 'eq', 'value' => 'x']]]],
            'unknown op' => [['operator' => 'and', 'conditions' => [['field' => 'email', 'op' => 'regex', 'value' => 'x']]]],
            'in needs list' => [['operator' => 'and', 'conditions' => [['field' => 'tag', 'op' => 'in', 'value' => 'vip']]]],
            'eq needs scalar' => [['operator' => 'and', 'conditions' => [['field' => 'email', 'op' => 'eq', 'value' => ['a']]]]],
            'missing value' => [['operator' => 'and', 'conditions' => [['field' => 'email', 'op' => 'eq']]]],
            'consent bad status' => [['operator' => 'and', 'conditions' => [['field' => 'consent.email', 'op' => 'eq', 'value' => 'maybe']]]],
            'consent bad channel' => [['operator' => 'and', 'conditions' => [['field' => 'consent.fax', 'op' => 'eq', 'value' => 'subscribed']]]],
            'consent contains' => [['operator' => 'and', 'conditions' => [['field' => 'consent.email', 'op' => 'contains', 'value' => 'sub']]]],
            'too many conditions' => [['operator' => 'and', 'conditions' => array_fill(0, 21, ['field' => 'email', 'op' => 'is_null'])]],
        ];
    }

    public function test_preview_counts_with_tag_and_consent_rules_without_materializing(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $vip = Tag::factory()->create(['store_id' => $store->id, 'name' => 'VIP', 'slug' => 'vip']);

        $make = function (string $email, bool $isVip, string $emailStatus) use ($store, $vip) {
            $c = $this->contactIn($store, ['email' => $email]);
            $c->consents()->where('channel', 'email')->update(['status' => $emailStatus]);
            $isVip && $c->tags()->attach($vip->id, ['created_at' => now()]);

            return $c;
        };

        $match1 = $make('a@gmail.com', true, 'subscribed');
        $match2 = $make('b@gmail.com', true, 'subscribed');
        $make('c@gmail.com', true, 'unsubscribed');      // wrong consent
        $make('d@gmail.com', false, 'subscribed');       // no tag
        $make('e@yahoo.com', true, 'subscribed');        // wrong domain

        // Same shape in another store must never be counted.
        $otherStore = $this->storeIn($workspace);
        $otherVip = Tag::factory()->create(['store_id' => $otherStore->id, 'slug' => 'vip', 'name' => 'vip']);
        $foreign = $this->contactIn($otherStore, ['email' => 'z@gmail.com']);
        $foreign->consents()->where('channel', 'email')->update(['status' => 'subscribed']);
        $foreign->tags()->attach($otherVip->id, ['created_at' => now()]);

        $id = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/segments", [
            'name' => 'Subscribed VIP gmail',
            'rules' => $this->rules(
                ['field' => 'email', 'op' => 'contains', 'value' => '@gmail.com'],
                ['field' => 'tag', 'op' => 'in', 'value' => ['vip']],
                ['field' => 'consent.email', 'op' => 'eq', 'value' => 'subscribed'],
            ),
        ])->assertCreated()->json('data.id');

        $response = $this->spa()->actingAs($admin)->postJson("/api/v1/segments/{$id}/preview")
            ->assertOk()->assertJsonPath('count', 2)->assertJsonCount(2, 'sample');

        $this->assertEqualsCanonicalizing([$match1->id, $match2->id], array_column($response->json('sample'), 'id'));
        $this->assertNotNull($response->json('sample.0.tags'));
        $this->assertNotNull($response->json('sample.0.consents'));
        $this->assertFalse(Schema::hasTable('segment_members'));
    }

    public function test_preview_sample_is_limited_to_ten(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        Contact::factory()->count(12)->create(['store_id' => $store->id]);
        $segment = Segment::factory()->create(['store_id' => $store->id]); // no conditions = everyone

        $this->spa()->actingAs($admin)->postJson("/api/v1/segments/{$segment->id}/preview")
            ->assertOk()->assertJsonPath('count', 12)->assertJsonCount(10, 'sample');
    }

    public function test_query_builder_operators(): void
    {
        [, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $alice = $this->contactIn($store, ['email' => 'alice@x.com', 'first_name' => 'Alice', 'source' => 'import', 'phone' => '+15550000001']);
        $bob = $this->contactIn($store, ['email' => 'bob@x.com', 'first_name' => null, 'source' => null]);
        $carol = $this->contactIn($store, ['email' => 'carol_100%@y.com', 'first_name' => 'Carol', 'source' => 'manual']);
        $tag = Tag::factory()->create(['store_id' => $store->id, 'slug' => 'vip', 'name' => 'vip']);
        $alice->tags()->attach($tag->id, ['created_at' => now()]);
        $alice->consents()->where('channel', 'sms')->update(['status' => 'subscribed']);

        $run = function (string $operator, array $conditions) use ($store) {
            return app(SegmentQueryBuilder::class)
                ->apply(Contact::query(), $store, ['operator' => $operator, 'conditions' => $conditions])
                ->orderBy('email')->pluck('email')->all();
        };

        $this->assertSame(['alice@x.com'], $run('and', [['field' => 'email', 'op' => 'eq', 'value' => 'alice@x.com']]));
        $this->assertSame(['bob@x.com', 'carol_100%@y.com'], $run('and', [['field' => 'email', 'op' => 'neq', 'value' => 'alice@x.com']]));
        $this->assertSame(['alice@x.com', 'bob@x.com'], $run('or', [['field' => 'email', 'op' => 'starts_with', 'value' => 'b'], ['field' => 'email', 'op' => 'contains', 'value' => 'alice']]));
        // LIKE wildcards in user input are literal.
        $this->assertSame(['carol_100%@y.com'], $run('and', [['field' => 'email', 'op' => 'contains', 'value' => '_100%']]));
        $this->assertSame(['carol_100%@y.com'], $run('and', [['field' => 'email', 'op' => 'contains', 'value' => '%']]));
        $this->assertSame([], $run('and', [['field' => 'email', 'op' => 'contains', 'value' => 'a%x']]));
        $this->assertSame(['alice@x.com', 'carol_100%@y.com'], $run('and', [['field' => 'source', 'op' => 'in', 'value' => ['import', 'manual']]]));
        $this->assertSame(['alice@x.com'], $run('and', [['field' => 'source', 'op' => 'not_in', 'value' => ['manual']], ['field' => 'source', 'op' => 'is_not_null']]));
        $this->assertSame(['bob@x.com'], $run('and', [['field' => 'first_name', 'op' => 'is_null']]));
        $this->assertSame(['alice@x.com'], $run('and', [['field' => 'phone', 'op' => 'is_not_null']]));
        $this->assertSame(['alice@x.com'], $run('and', [['field' => 'tag', 'op' => 'eq', 'value' => 'vip']]));
        $this->assertSame(['bob@x.com', 'carol_100%@y.com'], $run('and', [['field' => 'tag', 'op' => 'not_in', 'value' => ['vip']]]));
        $this->assertSame(['bob@x.com', 'carol_100%@y.com'], $run('and', [['field' => 'tag', 'op' => 'is_null']]));
        $this->assertSame(['alice@x.com'], $run('and', [['field' => 'consent.sms', 'op' => 'eq', 'value' => 'subscribed']]));
        $this->assertSame(['bob@x.com', 'carol_100%@y.com'], $run('and', [['field' => 'consent.sms', 'op' => 'neq', 'value' => 'subscribed']]));
        $this->assertSame(['alice@x.com', 'bob@x.com', 'carol_100%@y.com'], $run('and', [['field' => 'consent.push', 'op' => 'in', 'value' => ['nonsubscribed']]]));

        // Stale / unknown conditions are ignored on read instead of throwing.
        $this->assertCount(3, $run('and', [['field' => 'bogus', 'op' => 'eq', 'value' => 'x'], ['field' => 'email', 'op' => 'regex', 'value' => 'x'], 'junk']));
    }

    public function test_segment_access_control(): void
    {
        [, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $segment = Segment::factory()->create(['store_id' => $store->id]);
        $outsider = User::factory()->create();
        $marketer = $this->marketerIn($workspace);
        $valid = ['name' => 'S', 'rules' => $this->rules()];

        $this->spa()->postJson("/api/v1/segments/{$segment->id}/preview")->assertUnauthorized();
        $this->spa()->actingAs($outsider)->getJson("/api/v1/stores/{$store->id}/segments")->assertForbidden();
        $this->spa()->actingAs($outsider)->postJson("/api/v1/stores/{$store->id}/segments", $valid)->assertForbidden();
        $this->spa()->actingAs($outsider)->getJson("/api/v1/segments/{$segment->id}")->assertForbidden();
        $this->spa()->actingAs($outsider)->putJson("/api/v1/segments/{$segment->id}", ['name' => 'x'])->assertForbidden();
        $this->spa()->actingAs($outsider)->deleteJson("/api/v1/segments/{$segment->id}")->assertForbidden();
        $this->spa()->actingAs($outsider)->postJson("/api/v1/segments/{$segment->id}/preview")->assertForbidden();

        $this->spa()->actingAs($marketer)->postJson("/api/v1/stores/{$store->id}/segments", $valid)->assertCreated();
        $this->spa()->actingAs($marketer)->postJson("/api/v1/segments/{$segment->id}/preview")->assertOk();
    }
}
