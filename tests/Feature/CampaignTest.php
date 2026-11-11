<?php

namespace Tests\Feature;

use App\Enums\CampaignAudienceType;
use App\Enums\CampaignChannel;
use App\Enums\CampaignStatus;
use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Models\Campaign;
use App\Models\Segment;
use App\Models\User;
use App\Support\Segments\SegmentQueryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class CampaignTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    public function test_draft_crud_and_list_filters(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $segment = Segment::factory()->create(['store_id' => $store->id]);

        $id = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/campaigns", [
            'name' => 'Spring launch',
            'channel' => 'email',
            'subject' => 'Hello',
            'preview_text' => 'Preview',
            'body' => 'Body copy',
            'audience_type' => 'segment',
            'segment_id' => $segment->id,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Spring launch')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonPath('data.segment_id', $segment->id)
            ->assertJsonPath('data.scheduled_at', null)
            ->assertJsonPath('data.audience_count', null)
            ->json('data.id');

        $smsId = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/campaigns", [
            'name' => 'SMS flash',
            'channel' => 'sms',
            'subject' => 'ignored',
            'preview_text' => 'ignored',
            'body' => 'Short text',
            'audience_type' => 'all_eligible',
        ])->assertCreated()
            ->assertJsonPath('data.subject', null)
            ->assertJsonPath('data.preview_text', null)
            ->assertJsonPath('data.segment_id', null)
            ->json('data.id');

        $this->spa()->actingAs($admin)->getJson("/api/v1/campaigns/{$id}")
            ->assertOk()
            ->assertJsonPath('data.body', 'Body copy');

        $this->spa()->actingAs($admin)->putJson("/api/v1/campaigns/{$id}", [
            'name' => 'Spring launch renamed',
            'body' => 'Updated body',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Spring launch renamed')
            ->assertJsonPath('data.body', 'Updated body');

        $this->spa()->actingAs($admin)->getJson("/api/v1/stores/{$store->id}/campaigns?q=Spring")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id);

        $this->spa()->actingAs($admin)->getJson("/api/v1/stores/{$store->id}/campaigns?channel=sms")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $smsId);

        $this->spa()->actingAs($admin)->getJson("/api/v1/stores/{$store->id}/campaigns?status=draft")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->spa()->actingAs($admin)->getJson("/api/v1/stores/{$store->id}/campaigns/summary")
            ->assertOk()
            ->assertExactJson(['total' => 2, 'draft' => 2, 'scheduled' => 0]);

        $this->spa()->actingAs($admin)->deleteJson("/api/v1/campaigns/{$smsId}")->assertNoContent();
        $this->assertSoftDeleted('campaigns', ['id' => $smsId]);
    }

    public function test_other_store_segment_is_rejected(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $other = $this->storeIn($workspace);
        $foreign = Segment::factory()->create(['store_id' => $other->id]);

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/campaigns", [
            'name' => 'Bad audience',
            'channel' => 'email',
            'audience_type' => 'segment',
            'segment_id' => $foreign->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('segment_id');

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/campaigns/audience-preview", [
            'channel' => 'email',
            'audience_type' => 'segment',
            'segment_id' => $foreign->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('segment_id');
    }

    public function test_schedule_validation_and_eligibility_exclusions(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $eligible = $this->contactIn($store, ['email' => 'ok@example.com']);
        $eligible->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Subscribed,
        ]);

        $unsubscribed = $this->contactIn($store, ['email' => 'no@example.com']);
        $unsubscribed->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Unsubscribed,
        ]);

        $review = $this->contactIn($store, ['email' => 'review@example.com']);
        $review->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Subscribed,
        ]);
        $review->forceFill(['consent_review_required_at' => now()])->save();

        $trashed = $this->contactIn($store, ['email' => 'gone@example.com']);
        $trashed->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Subscribed,
        ]);
        $trashed->delete();

        $empty = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/campaigns", [
            'name' => 'Needs content',
            'channel' => 'email',
            'audience_type' => 'all_eligible',
        ])->assertCreated()->json('data.id');

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$empty}/schedule", [
            'scheduled_at' => now('UTC')->addHour()->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['body', 'subject']);

        $this->spa()->actingAs($admin)->putJson("/api/v1/campaigns/{$empty}", [
            'subject' => 'Ready',
            'body' => 'Send me',
        ])->assertOk();

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/campaigns/audience-preview", [
            'channel' => 'email',
            'audience_type' => 'all_eligible',
        ])->assertOk()->assertJsonPath('count', 1);

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$empty}/schedule", [
            'scheduled_at' => now('UTC')->subMinute()->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$empty}/schedule", [
            'scheduled_at' => now('UTC')->addDay()->toIso8601String(),
        ])->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.audience_count', 1);

        $this->assertTrue($eligible->fresh()->load('consents')->isMarketingEligible(ConsentChannel::Email));
        $this->assertFalse($unsubscribed->fresh()->load('consents')->isMarketingEligible(ConsentChannel::Email));
        $this->assertFalse($review->fresh()->load('consents')->isMarketingEligible(ConsentChannel::Email));
        $this->assertFalse($trashed->fresh()->load('consents')->isMarketingEligible(ConsentChannel::Email));
    }

    public function test_segment_rules_apply_before_eligibility_gate(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $matchEligible = $this->contactIn($store, ['email' => 'a@gmail.com']);
        $matchEligible->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Subscribed,
        ]);

        $matchIneligible = $this->contactIn($store, ['email' => 'b@gmail.com']);
        $matchIneligible->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Nonsubscribed,
        ]);

        $nonMatch = $this->contactIn($store, ['email' => 'c@yahoo.com']);
        $nonMatch->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Subscribed,
        ]);

        $segment = Segment::factory()->create([
            'store_id' => $store->id,
            'rules' => [
                'operator' => 'and',
                'conditions' => [
                    ['field' => 'email', 'op' => 'contains', 'value' => '@gmail.com'],
                ],
            ],
        ]);

        // Segment preview remains rules-only (no eligibility gate).
        $this->assertSame(2, app(SegmentQueryBuilder::class)->forSegment($segment)->count());

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/campaigns/audience-preview", [
            'channel' => 'email',
            'audience_type' => 'segment',
            'segment_id' => $segment->id,
        ])->assertOk()->assertJsonPath('count', 1);

        $campaignId = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/campaigns", [
            'name' => 'Gmail only',
            'channel' => 'email',
            'subject' => 'Hi',
            'body' => 'Hello',
            'audience_type' => 'segment',
            'segment_id' => $segment->id,
        ])->assertCreated()->json('data.id');

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaignId}/schedule", [
            'scheduled_at' => now('UTC')->addHours(2)->toIso8601String(),
        ])->assertOk()->assertJsonPath('data.audience_count', 1);
    }

    public function test_scheduled_campaign_is_locked_until_unscheduled(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $campaign = Campaign::factory()->create([
            'store_id' => $store->id,
            'created_by' => $admin->id,
            'status' => CampaignStatus::Scheduled,
            'scheduled_at' => now('UTC')->addDay(),
            'audience_count' => 3,
            'body' => 'Locked',
            'subject' => 'Locked subject',
        ]);

        $this->spa()->actingAs($admin)->putJson("/api/v1/campaigns/{$campaign->id}", [
            'name' => 'Nope',
        ])->assertStatus(409);

        $this->spa()->actingAs($admin)->deleteJson("/api/v1/campaigns/{$campaign->id}")
            ->assertStatus(409);

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaign->id}/schedule", [
            'scheduled_at' => now('UTC')->addDays(2)->toIso8601String(),
        ])->assertStatus(409);

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaign->id}/unschedule")
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.scheduled_at', null)
            ->assertJsonPath('data.audience_count', null);

        $this->spa()->actingAs($admin)->putJson("/api/v1/campaigns/{$campaign->id}", [
            'name' => 'Editable again',
        ])->assertOk()->assertJsonPath('data.name', 'Editable again');

        $this->spa()->actingAs($admin)->getJson("/api/v1/stores/{$store->id}/campaigns/summary")
            ->assertOk()
            ->assertExactJson(['total' => 1, 'draft' => 1, 'scheduled' => 0]);
    }

    public function test_duplicate_creates_draft_copy(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $segment = Segment::factory()->create(['store_id' => $store->id]);

        $campaign = Campaign::factory()->scheduled()->create([
            'store_id' => $store->id,
            'created_by' => $admin->id,
            'name' => 'Original',
            'channel' => CampaignChannel::Email,
            'subject' => 'Subject',
            'preview_text' => 'Preview',
            'body' => 'Body',
            'audience_type' => CampaignAudienceType::Segment,
            'segment_id' => $segment->id,
            'audience_count' => 12,
        ]);

        $copyId = $this->spa()->actingAs($admin)
            ->postJson("/api/v1/campaigns/{$campaign->id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.name', 'Copy of Original')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.scheduled_at', null)
            ->assertJsonPath('data.audience_count', null)
            ->assertJsonPath('data.subject', 'Subject')
            ->assertJsonPath('data.segment_id', $segment->id)
            ->json('data.id');

        $this->assertNotSame($campaign->id, $copyId);
        $this->assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'status' => 'scheduled',
            'audience_count' => 12,
        ]);
    }

    public function test_zero_eligible_count_is_allowed_on_schedule(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $this->contactIn($store, ['email' => 'nobody@example.com']);

        $id = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/campaigns", [
            'name' => 'Empty audience',
            'channel' => 'sms',
            'body' => 'Ping',
            'audience_type' => 'all_eligible',
        ])->assertCreated()->json('data.id');

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$id}/schedule", [
            'scheduled_at' => now('UTC')->addHour()->toIso8601String(),
        ])->assertOk()->assertJsonPath('data.audience_count', 0);
    }

    public function test_cross_store_and_outsider_access_is_denied(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $campaign = Campaign::factory()->create([
            'store_id' => $store->id,
            'created_by' => $admin->id,
        ]);

        [$otherAdmin, $otherWorkspace] = $this->adminWithWorkspace('Other');
        $otherStore = $this->storeIn($otherWorkspace);

        $this->spa()->actingAs($otherAdmin)->getJson("/api/v1/campaigns/{$campaign->id}")
            ->assertForbidden();
        $this->spa()->actingAs($otherAdmin)->getJson("/api/v1/stores/{$store->id}/campaigns")
            ->assertForbidden();
        $this->spa()->actingAs($otherAdmin)->getJson("/api/v1/stores/{$otherStore->id}/campaigns")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $outsider = User::factory()->create();
        $this->spa()->actingAs($outsider)->getJson("/api/v1/campaigns/{$campaign->id}")
            ->assertForbidden();
        $this->spa()->actingAs($outsider)->postJson("/api/v1/stores/{$store->id}/campaigns", [
            'name' => 'x',
            'channel' => 'email',
            'audience_type' => 'all_eligible',
        ])->assertForbidden();

        $marketer = $this->marketerIn($workspace);
        $this->spa()->actingAs($marketer)->postJson("/api/v1/stores/{$store->id}/campaigns", [
            'name' => 'By marketer',
            'channel' => 'email',
            'audience_type' => 'all_eligible',
        ])->assertCreated();
    }

    public function test_soft_deleted_campaign_is_not_found(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $campaign = Campaign::factory()->create([
            'store_id' => $store->id,
            'created_by' => $admin->id,
        ]);
        $campaign->delete();

        $this->spa()->actingAs($admin)->getJson("/api/v1/campaigns/{$campaign->id}")
            ->assertNotFound();
    }
}
