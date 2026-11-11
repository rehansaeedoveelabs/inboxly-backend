<?php

namespace App\Actions;

use App\Enums\CampaignAudienceType;
use App\Enums\CampaignChannel;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Support\Campaigns\CampaignAudienceCounter;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ScheduleCampaign
{
    public function __construct(private CampaignAudienceCounter $audienceCounter) {}

    /**
     * @param  array{scheduled_at: string|\DateTimeInterface}  $data
     */
    public function handle(Campaign $campaign, array $data): Campaign
    {
        if ($campaign->isScheduled()) {
            throw new ConflictHttpException('Campaign is already scheduled.');
        }

        $this->assertReadyToSchedule($campaign);

        $scheduledAt = CarbonImmutable::parse($data['scheduled_at'])->utc();

        if ($scheduledAt->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['The scheduled time must be in the future (UTC).'],
            ]);
        }

        if ($campaign->audience_type === CampaignAudienceType::Segment) {
            $segment = $campaign->segment;
            if ($segment === null || $segment->store_id !== $campaign->store_id) {
                throw ValidationException::withMessages([
                    'segment_id' => ['A valid segment on this store is required.'],
                ]);
            }
        }

        $count = $this->audienceCounter->count(
            $campaign->store,
            $campaign->channel,
            $campaign->audience_type,
            $campaign->audience_type === CampaignAudienceType::Segment ? $campaign->segment : null,
        );

        $campaign->forceFill([
            'status' => CampaignStatus::Scheduled,
            'scheduled_at' => $scheduledAt,
            'audience_count' => $count,
        ])->save();

        return $campaign->refresh();
    }

    private function assertReadyToSchedule(Campaign $campaign): void
    {
        $errors = [];

        $hasHtml = $campaign->channel === CampaignChannel::Email
            && is_string($campaign->html_body)
            && trim($campaign->html_body) !== '';
        $hasBody = is_string($campaign->body) && trim($campaign->body) !== '';

        if (! $hasHtml && ! $hasBody) {
            $errors['body'] = ['Content is required before scheduling.'];
        }

        if ($campaign->channel === CampaignChannel::Email) {
            $subject = is_string($campaign->subject) ? trim($campaign->subject) : '';
            if ($subject === '') {
                $errors['subject'] = ['Subject is required for email campaigns.'];
            }
        }

        if ($campaign->audience_type === CampaignAudienceType::Segment && blank($campaign->segment_id)) {
            $errors['segment_id'] = ['A segment is required for this audience type.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
