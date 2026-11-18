<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AutomationStatus;
use App\Enums\CampaignStatus;
use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Http\Controllers\Controller;
use App\Models\AutomationEnrollment;
use App\Models\Contact;
use App\Models\ContactConsent;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Store-scoped live metrics for dashboard / insights (no mock revenue).
 */
class StoreInsightsController extends Controller
{
    public function overview(Store $store): JsonResponse
    {
        $this->authorize('manageAudience', $store);

        $campaignBase = $store->campaigns();
        $automationBase = $store->automations();

        $emailSubscribers = ContactConsent::query()
            ->where('channel', ConsentChannel::Email)
            ->where('status', ConsentStatus::Subscribed)
            ->whereHas('contact', fn ($q) => $q->where('store_id', $store->getKey()))
            ->count();

        $smsSubscribers = ContactConsent::query()
            ->where('channel', ConsentChannel::Sms)
            ->where('status', ConsentStatus::Subscribed)
            ->whereHas('contact', fn ($q) => $q->where('store_id', $store->getKey()))
            ->count();

        $recentCampaigns = $store->campaigns()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'name', 'channel', 'status', 'updated_at'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'channel' => $c->channel?->value ?? $c->channel,
                'status' => $c->status?->value ?? $c->status,
                'updated_at' => optional($c->updated_at)?->toIso8601String(),
            ])
            ->values();

        $recentAutomations = $store->automations()
            ->withCount('enrollments')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'status' => $a->status?->value ?? $a->status,
                'enrollments_count' => (int) $a->enrollments_count,
                'updated_at' => optional($a->updated_at)?->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'contacts_total' => Contact::query()->where('store_id', $store->getKey())->count(),
            'email_subscribers' => $emailSubscribers,
            'sms_subscribers' => $smsSubscribers,
            'campaigns' => [
                'total' => (clone $campaignBase)->count(),
                'draft' => (clone $campaignBase)->where('status', CampaignStatus::Draft)->count(),
                'scheduled' => (clone $campaignBase)->where('status', CampaignStatus::Scheduled)->count(),
                'sending' => (clone $campaignBase)->where('status', CampaignStatus::Sending)->count(),
                'sent' => (clone $campaignBase)->whereIn('status', [
                    CampaignStatus::Sent,
                    CampaignStatus::PartiallySent,
                ])->count(),
                'failed' => (clone $campaignBase)->where('status', CampaignStatus::Failed)->count(),
            ],
            'automations' => [
                'total' => (clone $automationBase)->count(),
                'draft' => (clone $automationBase)->where('status', AutomationStatus::Draft)->count(),
                'active' => (clone $automationBase)->where('status', AutomationStatus::Active)->count(),
                'paused' => (clone $automationBase)->where('status', AutomationStatus::Paused)->count(),
                'archived' => (clone $automationBase)->where('status', AutomationStatus::Archived)->count(),
                'enrollments_total' => AutomationEnrollment::query()
                    ->where('store_id', $store->getKey())
                    ->count(),
            ],
            'audience_series' => $this->audienceSeries($store),
            'recent_campaigns' => $recentCampaigns,
            'recent_automations' => $recentAutomations,
        ]);
    }

    /**
     * @return list<array{label: string, contacts: int, email: int, sms: int}>
     */
    private function audienceSeries(Store $store): array
    {
        $now = CarbonImmutable::now('UTC')->startOfWeek();
        $series = [];

        for ($i = 3; $i >= 0; $i--) {
            $start = $now->subWeeks($i);
            $end = $start->addWeek();
            $label = 'W'.(4 - $i);

            $contacts = Contact::query()
                ->where('store_id', $store->getKey())
                ->where('created_at', '>=', $start)
                ->where('created_at', '<', $end)
                ->count();

            $email = ContactConsent::query()
                ->where('channel', ConsentChannel::Email)
                ->where('status', ConsentStatus::Subscribed)
                ->where('updated_at', '>=', $start)
                ->where('updated_at', '<', $end)
                ->whereHas('contact', fn ($q) => $q->where('store_id', $store->getKey()))
                ->count();

            $sms = ContactConsent::query()
                ->where('channel', ConsentChannel::Sms)
                ->where('status', ConsentStatus::Subscribed)
                ->where('updated_at', '>=', $start)
                ->where('updated_at', '<', $end)
                ->whereHas('contact', fn ($q) => $q->where('store_id', $store->getKey()))
                ->count();

            $series[] = [
                'label' => $label,
                'contacts' => $contacts,
                'email' => $email,
                'sms' => $sms,
            ];
        }

        return $series;
    }
}
