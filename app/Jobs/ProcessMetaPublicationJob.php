<?php

namespace App\Jobs;

use App\Actions\Meta\RefreshMetaPageToken;
use App\Enums\MetaPublicationStatus;
use App\Models\Asset;
use App\Models\CampaignMetaPublication;
use App\Models\MetaConnection;
use App\Support\Meta\Contracts\MetaProvider;
use App\Support\Meta\MetaDriverGuard;
use App\Support\Meta\MetaPublishRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProcessMetaPublicationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $publicationId) {}

    public function handle(MetaProvider $provider, RefreshMetaPageToken $refreshPageToken): void
    {
        /** @var CampaignMetaPublication|null $publication */
        $publication = CampaignMetaPublication::query()->find($this->publicationId);
        if ($publication === null) {
            return;
        }

        if (! in_array($publication->status, [
            MetaPublicationStatus::Queued,
            MetaPublicationStatus::Publishing,
        ], true)) {
            return;
        }

        // Atomic expired-lease recovery → outcome_unknown (never republish automatically).
        if ($publication->status === MetaPublicationStatus::Publishing
            && $publication->lease_expires_at !== null
            && $publication->lease_expires_at->isPast()
            && $publication->provider_post_id === null) {
            $reclaimed = DB::table('campaign_meta_publications')
                ->where('id', $publication->getKey())
                ->where('status', MetaPublicationStatus::Publishing->value)
                ->whereNull('provider_post_id')
                ->whereNotNull('lease_expires_at')
                ->where('lease_expires_at', '<=', now())
                ->update([
                    'status' => MetaPublicationStatus::OutcomeUnknown->value,
                    'error_code' => 'ambiguous_outcome',
                    'error_message' => 'Publish may have succeeded but the outcome was not confirmed. Reconcile or resolve deliberately.',
                    'error_retryable' => false,
                    'lease_expires_at' => null,
                    'lease_token' => null,
                    'updated_at' => now(),
                ]);
            if ($reclaimed === 1) {
                $this->logTransition(
                    $publication,
                    MetaPublicationStatus::OutcomeUnknown->value,
                    'ambiguous_outcome',
                    (bool) $publication->simulated,
                );
            }

            return;
        }

        $leaseSeconds = max(30, (int) config('services.meta.publish_lease_seconds', 180));
        $leaseToken = (string) Str::ulid();
        $leaseExpiresAt = now()->addSeconds($leaseSeconds);

        $claimed = DB::table('campaign_meta_publications')
            ->where('id', $publication->getKey())
            ->where('status', MetaPublicationStatus::Queued->value)
            ->where(function ($query) {
                $query->whereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '<=', now());
            })
            ->update([
                'status' => MetaPublicationStatus::Publishing->value,
                'publishing_started_at' => now(),
                'lease_expires_at' => $leaseExpiresAt,
                'lease_token' => $leaseToken,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return;
        }

        $publication->refresh();
        $attemptsUpdated = DB::table('campaign_meta_publications')
            ->where('id', $publication->getKey())
            ->where('status', MetaPublicationStatus::Publishing->value)
            ->where('lease_token', $leaseToken)
            ->update([
                'attempts' => $publication->attempts + 1,
                'updated_at' => now(),
            ]);
        if ($attemptsUpdated !== 1) {
            return;
        }
        $publication->refresh();

        try {
            MetaDriverGuard::assertPublishAllowed();
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? $e->getMessage();
            $code = str_contains(mb_strtolower((string) $message), 'not configured')
                || str_contains(mb_strtolower((string) $message), 'redirect uri')
                ? 'meta_not_configured'
                : 'fake_driver_blocked';
            $this->failPermanent($publication, $leaseToken, $code, (string) $message);

            return;
        }

        $page = $publication->metaPage;
        $connection = MetaConnection::query()
            ->where('store_id', $publication->store_id)
            ->first();

        if ($page === null || $connection === null) {
            $this->failPermanent($publication, $leaseToken, 'missing_page', 'Meta Page or connection is missing.');

            return;
        }

        try {
            $page = $refreshPageToken->handle($connection, $page);
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? 'Page token refresh failed.';
            $this->failPermanent($publication, $leaseToken, 'page_token_refresh_failed', (string) $message);

            return;
        }

        $imagePath = null;
        $imageMime = null;
        if ($publication->asset_id) {
            $asset = Asset::query()
                ->where('store_id', $publication->store_id)
                ->whereKey($publication->asset_id)
                ->first();
            if ($asset === null || ! $asset->fileExists()) {
                $this->failPermanent($publication, $leaseToken, 'asset_missing', 'Attached image asset is missing.');

                return;
            }
            $disk = Storage::disk($asset->disk ?: 'local');
            $tmp = tempnam(sys_get_temp_dir(), 'meta_img_');
            if ($tmp === false) {
                $this->failTransient($publication, $leaseToken, 'temp_file', 'Unable to stage image for upload.');

                return;
            }
            file_put_contents($tmp, $disk->get($asset->path));
            $imagePath = $tmp;
            $imageMime = (string) $asset->mime_type;
        }

        $acceptedWithoutPersist = false;
        $providerPostId = null;

        try {
            $result = $provider->publishPagePost(new MetaPublishRequest(
                pageId: (string) $page->meta_page_id,
                pageAccessToken: (string) $page->page_access_token,
                message: (string) $publication->message_snapshot,
                link: $publication->link_snapshot,
                imageAbsolutePath: $imagePath,
                imageMimeType: $imageMime,
            ));

            if ($result->accepted) {
                $acceptedWithoutPersist = true;
                $providerPostId = $result->providerPostId;
                $saved = $this->transitionOwning($publication, $leaseToken, [
                    'status' => MetaPublicationStatus::Published->value,
                    'provider_post_id' => $providerPostId,
                    'provider' => $result->simulated ? 'fake' : 'meta',
                    'simulated' => ($result->simulated || $publication->simulated) ? 1 : 0,
                    'error_code' => null,
                    'error_message' => null,
                    'error_retryable' => false,
                    'published_at' => now(),
                    'lease_expires_at' => null,
                    'lease_token' => null,
                    'meta' => json_encode([
                        'simulated' => $result->simulated,
                    ]),
                ]);
                $acceptedWithoutPersist = false;
                if ($saved !== 1) {
                    // Another worker/operator moved state; do not republish.
                    return;
                }
                $this->logTransition($publication, MetaPublicationStatus::Published->value, null, (bool) ($result->simulated || $publication->simulated));

                return;
            }

            if ($result->ambiguous) {
                $this->markOutcomeUnknown(
                    $publication,
                    $leaseToken,
                    $result->errorCode ?? 'ambiguous_transport',
                    $result->errorMessage ?? 'Publish outcome is unknown.',
                    $providerPostId,
                );

                return;
            }

            if ($result->retryable) {
                $this->failTransient($publication, $leaseToken, $result->errorCode ?? 'retryable', $result->errorMessage ?? 'Transient publish failure.');

                return;
            }

            $this->failPermanent($publication, $leaseToken, $result->errorCode ?? 'publish_failed', $result->errorMessage ?? 'Publish failed.');
        } catch (Throwable $e) {
            if ($acceptedWithoutPersist) {
                $this->markOutcomeUnknown(
                    $publication,
                    $leaseToken,
                    'ambiguous_outcome',
                    'Provider may have accepted the post before confirmation was saved.',
                    $providerPostId,
                );

                return;
            }

            // Unexpected exception after the request may have been sent — treat as ambiguous.
            $this->markOutcomeUnknown(
                $publication,
                $leaseToken,
                'ambiguous_outcome',
                'Unexpected failure after the publish attempt; outcome is unknown.',
            );
        } finally {
            if (is_string($imagePath) && is_file($imagePath)) {
                @unlink($imagePath);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transitionOwning(CampaignMetaPublication $publication, string $leaseToken, array $attributes): int
    {
        $attributes['updated_at'] = now();

        return DB::table('campaign_meta_publications')
            ->where('id', $publication->getKey())
            ->where('status', MetaPublicationStatus::Publishing->value)
            ->where('lease_token', $leaseToken)
            ->update($attributes);
    }

    private function markOutcomeUnknown(
        CampaignMetaPublication $publication,
        string $leaseToken,
        string $code,
        string $message,
        ?string $providerPostId = null,
    ): void {
        $attrs = [
            'status' => MetaPublicationStatus::OutcomeUnknown->value,
            'error_code' => $code,
            'error_message' => mb_substr($message, 0, 900),
            'error_retryable' => false,
            'lease_expires_at' => null,
            'lease_token' => null,
        ];
        if ($providerPostId !== null) {
            $attrs['provider_post_id'] = $providerPostId;
        }

        if ($this->transitionOwning($publication, $leaseToken, $attrs) === 1) {
            $this->logTransition($publication, MetaPublicationStatus::OutcomeUnknown->value, $code, (bool) $publication->simulated);
        }
    }

    private function failPermanent(
        CampaignMetaPublication $publication,
        string $leaseToken,
        string $code,
        string $message,
    ): void {
        $updated = $this->transitionOwning($publication, $leaseToken, [
            'status' => MetaPublicationStatus::Failed->value,
            'error_code' => $code,
            'error_message' => mb_substr($message, 0, 900),
            'error_retryable' => false,
            'failed_at' => now(),
            'lease_expires_at' => null,
            'lease_token' => null,
        ]);
        if ($updated === 1) {
            $this->logTransition($publication, MetaPublicationStatus::Failed->value, $code, (bool) $publication->simulated);
        }
    }

    private function failTransient(
        CampaignMetaPublication $publication,
        string $leaseToken,
        string $code,
        string $message,
    ): void {
        $max = max(1, (int) config('services.meta.publish_max_attempts', 3));
        if ($publication->attempts < $max) {
            $updated = $this->transitionOwning($publication, $leaseToken, [
                'status' => MetaPublicationStatus::Queued->value,
                'error_code' => $code,
                'error_message' => mb_substr($message, 0, 900),
                'error_retryable' => true,
                'lease_expires_at' => null,
                'lease_token' => null,
            ]);

            if ($updated === 1) {
                $this->logTransition($publication, MetaPublicationStatus::Queued->value, $code, (bool) $publication->simulated);
                self::dispatch($publication->getKey())->delay(now()->addSeconds(min(60, 5 * $publication->attempts)));
            }

            return;
        }

        $this->failPermanent($publication, $leaseToken, $code, $message);
    }

    private function logTransition(
        CampaignMetaPublication $publication,
        string $status,
        ?string $errorCode,
        bool $simulated,
    ): void {
        Log::info('meta.publication.transition', [
            'publication_id' => $publication->getKey(),
            'store_id' => $publication->store_id,
            'campaign_id' => $publication->campaign_id,
            'status' => $status,
            'attempts' => $publication->attempts,
            'error_code' => $errorCode,
            'simulated' => $simulated,
        ]);
    }
}
