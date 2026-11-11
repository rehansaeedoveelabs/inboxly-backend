<?php

namespace App\Actions;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\User;

class DuplicateCampaign
{
    public function handle(Campaign $campaign, User $user): Campaign
    {
        return $campaign->store->campaigns()->create([
            'created_by' => $user->getKey(),
            'name' => 'Copy of '.$campaign->name,
            'channel' => $campaign->channel,
            'status' => CampaignStatus::Draft,
            'subject' => $campaign->subject,
            'preview_text' => $campaign->preview_text,
            'body' => $campaign->body,
            'audience_type' => $campaign->audience_type,
            'segment_id' => $campaign->segment_id,
            'scheduled_at' => null,
            'audience_count' => null,
        ]);
    }
}
