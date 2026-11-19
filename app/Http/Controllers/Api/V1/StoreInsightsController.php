<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AutomationStatus;
use App\Enums\CampaignStatus;
use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Http\Controllers\Controller;
use App\Models\AutomationEnrollment;
use App\Models\ConsentEvent;
use App\Models\Contact;
use App\Models\ContactConsent;
use App\Models\FormSubmission;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

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
     * Recent audience activity (form submits, new contacts, consent changes).
     */
    public function activity(Store $store): JsonResponse
    {
        $this->authorize('manageAudience', $store);

        $since = CarbonImmutable::now('UTC')->subDays(7);
        $events = collect()
            ->merge($this->formSubmitEvents($store, $since))
            ->merge($this->contactCreatedEvents($store, $since))
            ->merge($this->consentEvents($store, $since))
            ->sortByDesc('at')
            ->values()
            ->take(50)
            ->values();

        return response()->json([
            'data' => $events,
            'meta' => [
                'since' => $since->toIso8601String(),
                'count' => $events->count(),
            ],
        ]);
    }

    /**
     * @return Collection<int, array{id: string, type: string, contact: string, detail: string, at: string}>
     */
    private function formSubmitEvents(Store $store, CarbonImmutable $since): Collection
    {
        return FormSubmission::query()
            ->with(['contact:id,email,phone,first_name,last_name', 'form:id,name'])
            ->where('store_id', $store->getKey())
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->limit(40)
            ->get()
            ->map(fn (FormSubmission $row) => [
                'id' => 'form_'.$row->getKey(),
                'type' => 'form_submit',
                'contact' => $this->contactLabel($row->contact),
                'detail' => 'Submitted form “'.($row->form?->name ?? 'Untitled').'”',
                'at' => optional($row->created_at)?->toIso8601String() ?? '',
            ]);
    }

    /**
     * @return Collection<int, array{id: string, type: string, contact: string, detail: string, at: string}>
     */
    private function contactCreatedEvents(Store $store, CarbonImmutable $since): Collection
    {
        return Contact::query()
            ->where('store_id', $store->getKey())
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->limit(40)
            ->get(['id', 'email', 'phone', 'first_name', 'last_name', 'source', 'created_at'])
            ->map(fn (Contact $contact) => [
                'id' => 'contact_'.$contact->getKey(),
                'type' => 'contact_created',
                'contact' => $this->contactLabel($contact),
                'detail' => 'New contact'.($contact->source ? ' via '.$contact->source : ''),
                'at' => optional($contact->created_at)?->toIso8601String() ?? '',
            ]);
    }

    /**
     * @return Collection<int, array{id: string, type: string, contact: string, detail: string, at: string}>
     */
    private function consentEvents(Store $store, CarbonImmutable $since): Collection
    {
        return ConsentEvent::query()
            ->with(['contact:id,email,phone,first_name,last_name'])
            ->where('created_at', '>=', $since)
            ->whereHas('contact', fn ($q) => $q->where('store_id', $store->getKey()))
            ->orderByDesc('created_at')
            ->limit(40)
            ->get()
            ->map(function (ConsentEvent $event) {
                $channel = $event->channel?->value ?? 'channel';
                $status = $event->status?->value ?? 'updated';

                return [
                    'id' => 'consent_'.$event->getKey(),
                    'type' => $status === ConsentStatus::Subscribed->value ? 'subscribed' : 'consent_change',
                    'contact' => $this->contactLabel($event->contact),
                    'detail' => strtoupper($channel).' → '.$status
                        .($event->source ? ' ('.$event->source.')' : ''),
                    'at' => optional($event->created_at)?->toIso8601String() ?? '',
                ];
            });
    }

    private function contactLabel(?Contact $contact): string
    {
        if ($contact === null) {
            return 'Unknown contact';
        }

        $name = trim(($contact->first_name ?? '').' '.($contact->last_name ?? ''));
        if ($name !== '') {
            return $name;
        }

        return $contact->email ?: ($contact->phone ?: 'Contact');
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
