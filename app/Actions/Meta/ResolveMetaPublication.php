<?php

namespace App\Actions\Meta;

use App\Enums\MetaPublicationStatus;
use App\Models\CampaignMetaPublication;
use Illuminate\Validation\ValidationException;

/**
 * Explicit operator resolution for stuck outcome_unknown publications.
 * Never republishes. Actions that free the content for a later publish require
 * acknowledge_duplicate_risk=true because a Facebook post may already exist.
 */
class ResolveMetaPublication
{
    /**
     * @param  array{
     *     resolution: string,
     *     acknowledge_duplicate_risk?: bool,
     *     acknowledge_verified_on_facebook?: bool,
     *     provider_post_id?: string|null
     * }  $input
     */
    public function handle(CampaignMetaPublication $publication, array $input): CampaignMetaPublication
    {
        if ($publication->status !== MetaPublicationStatus::OutcomeUnknown) {
            throw ValidationException::withMessages([
                'publication' => ['Only publications with an unknown outcome can be resolved.'],
            ]);
        }

        $resolution = (string) ($input['resolution'] ?? '');

        return match ($resolution) {
            'mark_published' => $this->markPublished(
                $publication,
                $input['provider_post_id'] ?? null,
                (bool) ($input['acknowledge_verified_on_facebook'] ?? false),
            ),
            'mark_failed' => $this->markFailed($publication, (bool) ($input['acknowledge_duplicate_risk'] ?? false)),
            default => throw ValidationException::withMessages([
                'resolution' => ['Resolution must be mark_published or mark_failed.'],
            ]),
        };
    }

    private function markPublished(
        CampaignMetaPublication $publication,
        mixed $providerPostId,
        bool $acknowledgedVerified,
    ): CampaignMetaPublication {
        if (! $acknowledgedVerified) {
            throw ValidationException::withMessages([
                'acknowledge_verified_on_facebook' => [
                    'Confirm acknowledge_verified_on_facebook=true after independently verifying the post on Facebook.',
                ],
            ]);
        }

        $postId = is_string($providerPostId) ? trim($providerPostId) : '';
        if ($postId === '' && filled($publication->provider_post_id)) {
            $postId = (string) $publication->provider_post_id;
        }

        $publication->forceFill([
            'status' => MetaPublicationStatus::Published,
            'provider_post_id' => $postId !== '' ? $postId : $publication->provider_post_id,
            'provider' => $publication->provider ?: ($publication->simulated ? 'fake' : 'meta'),
            'error_code' => null,
            'error_message' => null,
            'error_retryable' => false,
            'published_at' => $publication->published_at ?? now(),
            'lease_expires_at' => null,
            'lease_token' => null,
            'meta' => array_merge($publication->meta ?? [], [
                'resolved' => 'mark_published',
                'resolved_at' => now()->toIso8601String(),
                'verified_on_facebook_acknowledged' => true,
            ]),
        ])->save();

        return $publication->fresh();
    }

    private function markFailed(CampaignMetaPublication $publication, bool $acknowledgeDuplicateRisk): CampaignMetaPublication
    {
        if (! $acknowledgeDuplicateRisk) {
            throw ValidationException::withMessages([
                'acknowledge_duplicate_risk' => [
                    'Confirm acknowledge_duplicate_risk=true. A Facebook post may already exist; marking failed allows retry or a new publish and risks duplicates.',
                ],
            ]);
        }

        $publication->forceFill([
            'status' => MetaPublicationStatus::Failed,
            'error_code' => 'resolved_failed',
            'error_message' => 'Marked failed by operator after ambiguous outcome. A Facebook post may already exist — republish carefully.',
            'error_retryable' => true,
            'failed_at' => now(),
            'lease_expires_at' => null,
            'lease_token' => null,
            'meta' => array_merge($publication->meta ?? [], [
                'resolved' => 'mark_failed',
                'resolved_at' => now()->toIso8601String(),
                'duplicate_risk_acknowledged' => true,
            ]),
        ])->save();

        return $publication->fresh();
    }
}
