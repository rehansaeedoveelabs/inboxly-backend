<?php

namespace App\Actions;

use App\Enums\CampaignAudienceType;
use App\Enums\CampaignChannel;
use App\Models\Campaign;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class UpdateCampaign
{
    /**
     * @param  array{
     *     name?: string,
     *     subject?: ?string,
     *     preview_text?: ?string,
     *     body?: ?string,
     *     audience_type?: CampaignAudienceType|string,
     *     segment_id?: ?string
     * }  $data
     */
    public function handle(Campaign $campaign, array $data): Campaign
    {
        if ($campaign->isScheduled()) {
            throw new ConflictHttpException('Scheduled campaigns cannot be edited. Unschedule first.');
        }

        if (array_key_exists('name', $data)) {
            $campaign->name = $data['name'];
        }

        if (array_key_exists('body', $data)) {
            $campaign->body = $data['body'];
        }

        if (array_key_exists('audience_type', $data)) {
            $audienceType = $data['audience_type'] instanceof CampaignAudienceType
                ? $data['audience_type']
                : CampaignAudienceType::from($data['audience_type']);
            $campaign->audience_type = $audienceType;

            if ($audienceType === CampaignAudienceType::AllEligible) {
                $campaign->segment_id = null;
            } elseif (array_key_exists('segment_id', $data)) {
                $campaign->segment_id = $data['segment_id'];
            }
        } elseif (array_key_exists('segment_id', $data)
            && $campaign->audience_type === CampaignAudienceType::Segment) {
            $campaign->segment_id = $data['segment_id'];
        }

        if ($campaign->channel === CampaignChannel::Sms) {
            $campaign->subject = null;
            $campaign->preview_text = null;
        } else {
            if (array_key_exists('subject', $data)) {
                $campaign->subject = $data['subject'];
            }
            if (array_key_exists('preview_text', $data)) {
                $campaign->preview_text = $data['preview_text'];
            }
        }

        $campaign->save();

        return $campaign->refresh();
    }
}
