<?php

namespace App\Actions;

use App\Enums\CampaignChannel;
use App\Enums\CampaignRecipientStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\Suppression;
use App\Support\Campaigns\CampaignAudienceCounter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Materializes recipient rows for a campaign send. Idempotent on (campaign_id, contact_id).
 */
class ResolveCampaignRecipients
{
    public const BATCH_SIZE = 500;

    public function __construct(private CampaignAudienceCounter $audienceCounter) {}

    public function handle(Campaign $campaign): int
    {
        $store = $campaign->store;
        if ($store === null) {
            return 0;
        }

        $segment = $campaign->audience_type->value === 'segment' ? $campaign->segment : null;
        $query = $this->audienceCounter->eligibleQuery(
            $store,
            $campaign->channel,
            $campaign->audience_type,
            $segment,
        )->orderBy('id');

        $created = 0;
        $query->select(['id', 'email', 'phone', 'first_name', 'last_name', 'consent_review_required_at', 'deleted_at'])
            ->chunkById(self::BATCH_SIZE, function ($contacts) use ($campaign, $store, &$created) {
                $contactIds = $contacts->pluck('id')->all();
                $existing = CampaignRecipient::query()
                    ->where('campaign_id', $campaign->getKey())
                    ->whereIn('contact_id', $contactIds)
                    ->pluck('contact_id')
                    ->all();
                $existingSet = array_fill_keys($existing, true);

                $addresses = $contacts->map(function (Contact $contact) use ($campaign) {
                    return $campaign->channel === CampaignChannel::Email
                        ? $contact->email
                        : $contact->phone;
                })->filter()->map(fn ($a) => mb_strtolower((string) $a))->unique()->values()->all();

                $suppressed = [];
                if ($addresses !== []) {
                    $suppressed = Suppression::query()
                        ->where('store_id', $store->getKey())
                        ->where('channel', $campaign->channel)
                        ->whereIn('address', $addresses)
                        ->pluck('address')
                        ->all();
                    $suppressed = array_fill_keys($suppressed, true);
                }

                $rows = [];
                $now = now();
                foreach ($contacts as $contact) {
                    if (isset($existingSet[$contact->getKey()])) {
                        continue;
                    }

                    $address = $campaign->channel === CampaignChannel::Email
                        ? $contact->email
                        : $contact->phone;
                    $address = is_string($address) ? mb_strtolower(trim($address)) : null;

                    $status = CampaignRecipientStatus::Pending;
                    $errorCode = null;
                    $errorMessage = null;

                    if ($address === null || $address === '') {
                        $status = CampaignRecipientStatus::Skipped;
                        $errorCode = 'missing_address';
                        $errorMessage = 'Contact has no address for this channel.';
                    } elseif (isset($suppressed[$address])) {
                        $status = CampaignRecipientStatus::Skipped;
                        $errorCode = 'suppressed';
                        $errorMessage = 'Address is on the store suppression list.';
                    } elseif (! $contact->isMarketingEligible($campaign->channel->toConsentChannel())) {
                        $status = CampaignRecipientStatus::Skipped;
                        $errorCode = 'ineligible';
                        $errorMessage = 'Contact is not marketing-eligible at send time.';
                    }

                    $rows[] = [
                        'id' => (string) Str::ulid(),
                        'campaign_id' => $campaign->getKey(),
                        'contact_id' => $contact->getKey(),
                        'channel' => $campaign->channel->value,
                        'address' => $address ?: '',
                        'status' => $status->value,
                        'error_code' => $errorCode,
                        'error_message' => $errorMessage,
                        'attempts' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('campaign_recipients')->insert($rows);
                    $created += count($rows);
                }
            });

        return $created;
    }
}
