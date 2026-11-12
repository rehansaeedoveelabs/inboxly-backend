<?php

namespace App\Actions\Meta;

use App\Enums\MetaPublicationStatus;
use App\Jobs\ProcessMetaPublicationJob;
use App\Models\CampaignMetaPublication;
use Illuminate\Validation\ValidationException;

class RetryMetaPublication
{
    public function handle(CampaignMetaPublication $publication): CampaignMetaPublication
    {
        if (! in_array($publication->status, [
            MetaPublicationStatus::Failed,
            MetaPublicationStatus::OutcomeUnknown,
        ], true)) {
            throw ValidationException::withMessages([
                'publication' => ['Only failed or unknown-outcome publications can be retried.'],
            ]);
        }

        if ($publication->status === MetaPublicationStatus::OutcomeUnknown) {
            throw ValidationException::withMessages([
                'publication' => ['Reconcile the unknown outcome before retrying. Blind retry risks duplicate posts.'],
            ]);
        }

        if (! $publication->error_retryable && $publication->error_code === 'ambiguous_outcome') {
            throw ValidationException::withMessages([
                'publication' => ['This failure is not safely retryable.'],
            ]);
        }

        // Permanent failures may still be explicitly retried by the user after fixing Meta state.
        $publication->forceFill([
            'status' => MetaPublicationStatus::Queued,
            'provider_post_id' => null,
            'attempts' => 0,
            'error_code' => null,
            'error_message' => null,
            'error_retryable' => false,
            'queued_at' => now(),
            'publishing_started_at' => null,
            'published_at' => null,
            'failed_at' => null,
            'lease_expires_at' => null,
        ])->save();

        ProcessMetaPublicationJob::dispatch($publication->getKey());

        return $publication->fresh();
    }
}
