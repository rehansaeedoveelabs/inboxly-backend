<?php

namespace App\Actions;

use App\Enums\CampaignChannel;
use App\Enums\CampaignStatus;
use App\Jobs\ProcessCampaignSendJob;
use App\Models\Campaign;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Claims a draft/scheduled campaign into sending and queues batch processing.
 */
class StartCampaignSend
{
    public function __construct(private ResolveCampaignRecipients $resolveRecipients) {}

    public function handle(Campaign $campaign): Campaign
    {
        if ($campaign->status === CampaignStatus::Sending) {
            throw new ConflictHttpException('Campaign is already sending.');
        }

        if ($campaign->status->isTerminal()) {
            throw new ConflictHttpException('This campaign can no longer be sent.');
        }

        if (! in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Scheduled], true)) {
            throw new ConflictHttpException('Only draft or scheduled campaigns can be sent.');
        }

        if ($campaign->channel === CampaignChannel::Sms) {
            throw ValidationException::withMessages([
                'channel' => ['SMS sending is not available yet.'],
            ]);
        }

        $this->assertReady($campaign);

        $claimed = DB::table('campaigns')
            ->where('id', $campaign->getKey())
            ->whereIn('status', [CampaignStatus::Draft->value, CampaignStatus::Scheduled->value])
            ->update([
                'status' => CampaignStatus::Sending->value,
                'sending_started_at' => now(),
                'sent_at' => null,
                'sent_count' => 0,
                'failed_count' => 0,
                'skipped_count' => 0,
                'send_error' => null,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            throw new ConflictHttpException('Unable to claim campaign for sending.');
        }

        $campaign->refresh();
        $this->resolveRecipients->handle($campaign);

        $recipientTotal = $campaign->recipients()->count();
        $campaign->forceFill(['audience_count' => $recipientTotal])->save();

        ProcessCampaignSendJob::dispatch($campaign->getKey());

        return $campaign->refresh();
    }

    private function assertReady(Campaign $campaign): void
    {
        if ($campaign->channel->value === 'email') {
            $subject = is_string($campaign->subject) ? trim($campaign->subject) : '';
            if ($subject === '') {
                throw ValidationException::withMessages([
                    'subject' => ['Subject is required before sending.'],
                ]);
            }
        }

        $hasHtml = is_string($campaign->html_body) && trim($campaign->html_body) !== '';
        $hasBody = is_string($campaign->body) && trim($campaign->body) !== '';
        if (! $hasHtml && ! $hasBody) {
            throw ValidationException::withMessages([
                'body' => ['Content is required before sending.'],
            ]);
        }
    }
}
