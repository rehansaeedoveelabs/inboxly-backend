<?php

namespace App\Actions;

use App\Enums\CampaignAudienceType;
use App\Enums\CampaignChannel;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Store;
use App\Models\User;

class CreateCampaign
{
    /**
     * @param  array{
     *     name: string,
     *     channel: CampaignChannel|string,
     *     subject?: ?string,
     *     preview_text?: ?string,
     *     body?: ?string,
     *     audience_type: CampaignAudienceType|string,
     *     segment_id?: ?string
     * }  $data
     */
    public function handle(Store $store, User $user, array $data): Campaign
    {
        $channel = $data['channel'] instanceof CampaignChannel
            ? $data['channel']
            : CampaignChannel::from($data['channel']);

        $audienceType = $data['audience_type'] instanceof CampaignAudienceType
            ? $data['audience_type']
            : CampaignAudienceType::from($data['audience_type']);

        $subject = $channel === CampaignChannel::Sms ? null : ($data['subject'] ?? null);
        $previewText = $channel === CampaignChannel::Sms ? null : ($data['preview_text'] ?? null);

        return $store->campaigns()->create([
            'created_by' => $user->getKey(),
            'name' => $data['name'],
            'channel' => $channel,
            'status' => CampaignStatus::Draft,
            'subject' => $subject,
            'preview_text' => $previewText,
            'body' => $data['body'] ?? null,
            'audience_type' => $audienceType,
            'segment_id' => $audienceType === CampaignAudienceType::Segment
                ? ($data['segment_id'] ?? null)
                : null,
            'scheduled_at' => null,
            'audience_count' => null,
        ]);
    }
}
