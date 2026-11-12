<?php

namespace App\Support\Campaigns;

use App\Enums\CampaignAudienceType;
use App\Enums\CampaignChannel;
use App\Enums\ConsentStatus;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\Store;
use App\Support\Segments\SegmentQueryBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * Counts contacts that would be eligible for a campaign send.
 * Segment membership stays rules-only; eligibility is applied here only.
 */
class CampaignAudienceCounter
{
    public function __construct(private SegmentQueryBuilder $segmentQueryBuilder) {}

    public function count(
        Store $store,
        CampaignChannel $channel,
        CampaignAudienceType $audienceType,
        ?Segment $segment = null,
    ): int {
        return $this->eligibleQuery($store, $channel, $audienceType, $segment)->count();
    }

    /** @return Builder<Contact> */
    public function eligibleQuery(
        Store $store,
        CampaignChannel $channel,
        CampaignAudienceType $audienceType,
        ?Segment $segment = null,
    ): Builder {
        $query = match ($audienceType) {
            CampaignAudienceType::Segment => $this->segmentQueryBuilder->forSegment(
                $segment ?? throw new \InvalidArgumentException('Segment is required for segment audiences.'),
            ),
            CampaignAudienceType::AllEligible => Contact::query()->where(
                (new Contact)->getTable().'.store_id',
                $store->getKey(),
            ),
        };

        $consentChannel = $channel->toConsentChannel()->value;

        $contactsTable = (new Contact)->getTable();

        return $query
            ->whereNull($contactsTable.'.consent_review_required_at')
            ->whereHas('consents', function (Builder $consents) use ($consentChannel) {
                $consents
                    ->where('channel', $consentChannel)
                    ->where('status', ConsentStatus::Subscribed->value);
            })
            ->whereNotExists(function ($sub) use ($store, $channel, $contactsTable) {
                $addressColumn = $channel === CampaignChannel::Email ? 'email' : 'phone';
                $sub->selectRaw('1')
                    ->from('suppressions')
                    ->whereColumn('suppressions.address', $contactsTable.'.'.$addressColumn)
                    ->where('suppressions.store_id', $store->getKey())
                    ->where('suppressions.channel', $channel->value);
            });
    }
}
