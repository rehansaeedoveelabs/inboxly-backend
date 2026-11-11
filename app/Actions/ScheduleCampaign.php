<?php

namespace App\Actions;

use App\Enums\CampaignAudienceType;
use App\Enums\CampaignChannel;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Support\Campaigns\CampaignAudienceCounter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
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
        $rules = [
            'body' => ['required', 'string', 'min:1'],
        ];

        if ($campaign->channel === CampaignChannel::Email) {
            $rules['subject'] = ['required', 'string', 'min:1'];
        }

        $payload = [
            'body' => is_string($campaign->body) ? trim($campaign->body) : $campaign->body,
            'subject' => is_string($campaign->subject) ? trim($campaign->subject) : $campaign->subject,
        ];

        $validator = Validator::make($payload, $rules, [
            'body.required' => 'Content is required before scheduling.',
            'subject.required' => 'Subject is required for email campaigns.',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        if ($campaign->audience_type === CampaignAudienceType::Segment && blank($campaign->segment_id)) {
            throw ValidationException::withMessages([
                'segment_id' => ['A segment is required for this audience type.'],
            ]);
        }
    }
}
