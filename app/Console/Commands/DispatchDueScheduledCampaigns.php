<?php

namespace App\Console\Commands;

use App\Actions\StartCampaignSend;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Illuminate\Console\Command;
use Throwable;

class DispatchDueScheduledCampaigns extends Command
{
    protected $signature = 'campaigns:dispatch-due {--limit=20 : Max campaigns to claim per run}';

    protected $description = 'Start sending for scheduled campaigns whose scheduled_at is due';

    public function handle(StartCampaignSend $startCampaignSend): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $due = Campaign::query()
            ->where('status', CampaignStatus::Scheduled)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now('UTC'))
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->get();

        $started = 0;
        foreach ($due as $campaign) {
            try {
                $startCampaignSend->handle($campaign);
                $started++;
                $this->info('Started campaign '.$campaign->getKey());
            } catch (Throwable $e) {
                $this->error('Failed campaign '.$campaign->getKey().': '.$e->getMessage());
                $campaign->forceFill([
                    'send_error' => mb_substr($e->getMessage(), 0, 1900),
                ])->save();
            }
        }

        $this->info("Started {$started} of {$due->count()} due campaign(s).");

        return self::SUCCESS;
    }
}
