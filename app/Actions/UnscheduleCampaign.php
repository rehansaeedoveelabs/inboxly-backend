<?php

namespace App\Actions;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class UnscheduleCampaign
{
    public function handle(Campaign $campaign): Campaign
    {
        if (! $campaign->isScheduled()) {
            throw new ConflictHttpException('Only scheduled campaigns can be unscheduled.');
        }

        $campaign->forceFill([
            'status' => CampaignStatus::Draft,
            'scheduled_at' => null,
            'audience_count' => null,
        ])->save();

        return $campaign->refresh();
    }
}
