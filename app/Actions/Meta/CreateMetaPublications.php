<?php

namespace App\Actions\Meta;

use App\Enums\MetaPublicationStatus;
use App\Jobs\ProcessMetaPublicationJob;
use App\Models\Campaign;
use App\Models\CampaignMetaPublication;
use App\Models\MetaConnection;
use App\Models\MetaPage;
use App\Support\Meta\MetaDriverGuard;
use App\Support\Meta\MetaUtm;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateMetaPublications
{
    /**
     * @param  list<string>  $metaPageRowIds  Inboxly MetaPage ULIDs
     * @return list<CampaignMetaPublication>
     */
    public function handle(Campaign $campaign, array $metaPageRowIds, bool $confirmRepublish = false): array
    {
        MetaDriverGuard::assertPublishAllowed();

        $message = trim((string) ($campaign->meta_message ?? ''));
        if ($message === '') {
            throw ValidationException::withMessages([
                'message' => ['Save a Facebook message before publishing.'],
            ]);
        }

        $pageIds = array_values(array_unique(array_filter(array_map(
            static fn ($id) => is_string($id) ? trim($id) : '',
            $metaPageRowIds,
        ), static fn (string $id) => $id !== '')));

        if ($pageIds === []) {
            throw ValidationException::withMessages([
                'meta_page_ids' => ['Select at least one Facebook Page.'],
            ]);
        }

        $connection = MetaConnection::query()
            ->where('store_id', $campaign->store_id)
            ->first();

        if ($connection === null) {
            throw ValidationException::withMessages([
                'meta' => ['Connect and authenticate Meta before publishing.'],
            ]);
        }

        $unusable = $connection->unusableReason();
        if ($unusable !== null) {
            if (in_array($unusable, ['user_token_expired', 'data_access_expired', 'needs_reauth'], true)) {
                $code = $unusable === 'needs_reauth'
                    ? ($connection->last_error_code ?? 'needs_reauth')
                    : $unusable;
                // Leave message null so publicErrorGuidance() can map from the code.
                $connection->markNeedsReauth($code, null);
            }

            throw ValidationException::withMessages([
                'meta' => [
                    $connection->fresh()->publicErrorGuidance()
                        ?? 'Connect and authenticate Meta before publishing.',
                ],
            ]);
        }

        $missingScopes = $connection->missingPublishScopes();
        if ($missingScopes !== []) {
            throw ValidationException::withMessages([
                'meta' => ['Meta connection is missing required permissions: '.implode(', ', $missingScopes).'. Reconnect Meta.'],
            ]);
        }

        $pages = MetaPage::query()
            ->where('store_id', $campaign->store_id)
            ->whereIn('id', $pageIds)
            ->get();

        if ($pages->count() !== count($pageIds)) {
            throw ValidationException::withMessages([
                'meta_page_ids' => ['One or more selected Pages were not found for this store.'],
            ]);
        }

        foreach ($pages as $page) {
            if (! $page->canPublishContent()) {
                throw ValidationException::withMessages([
                    'meta_page_ids' => ["Page {$page->name} cannot publish (CREATE_CONTENT required)."],
                ]);
            }
        }

        $link = $campaign->meta_link;
        if (is_string($link) && $link !== '') {
            $link = MetaUtm::append($link, [
                'utm_source' => 'facebook',
                'utm_medium' => 'social',
                'utm_campaign' => (string) $campaign->getKey(),
            ]);
        } else {
            $link = null;
        }

        $contentHash = CampaignMetaPublication::makeContentHash(
            $message,
            $link,
            $campaign->meta_asset_id,
        );

        $simulated = MetaDriverGuard::isFake();

        $created = DB::transaction(function () use (
            $campaign,
            $pages,
            $message,
            $link,
            $contentHash,
            $simulated,
            $confirmRepublish,
        ) {
            $rows = [];
            foreach ($pages as $page) {
                $rows[] = $this->createOrReuseForPage(
                    $campaign,
                    $page,
                    $message,
                    $link,
                    $contentHash,
                    $simulated,
                    $confirmRepublish,
                );
            }

            return $rows;
        });

        foreach ($created as $publication) {
            ProcessMetaPublicationJob::dispatch($publication->getKey());
        }

        return $created;
    }

    private function createOrReuseForPage(
        Campaign $campaign,
        MetaPage $page,
        string $message,
        ?string $link,
        string $contentHash,
        bool $simulated,
        bool $confirmRepublish,
    ): CampaignMetaPublication {
        $blocking = CampaignMetaPublication::query()
            ->where('campaign_id', $campaign->getKey())
            ->where('meta_page_id', $page->getKey())
            ->where('content_hash', $contentHash)
            ->whereIn('status', [
                MetaPublicationStatus::Queued->value,
                MetaPublicationStatus::Publishing->value,
                MetaPublicationStatus::Published->value,
                MetaPublicationStatus::OutcomeUnknown->value,
            ])
            ->lockForUpdate()
            ->orderByDesc('created_at')
            ->get();

        $inFlight = $blocking->first(fn (CampaignMetaPublication $row) => in_array($row->status, [
            MetaPublicationStatus::Queued,
            MetaPublicationStatus::Publishing,
            MetaPublicationStatus::OutcomeUnknown,
        ], true));

        if ($inFlight !== null) {
            throw ValidationException::withMessages([
                'meta' => [
                    $inFlight->status === MetaPublicationStatus::OutcomeUnknown
                        ? 'A publication with this content has an unknown Facebook outcome for '.$page->name.'. Reconcile or resolve it before publishing again.'
                        : 'A publication with this content is already queued or publishing for '.$page->name.'.',
                ],
            ]);
        }

        $published = $blocking->first(fn (CampaignMetaPublication $row) => $row->status === MetaPublicationStatus::Published);
        if ($published !== null && ! $confirmRepublish) {
            throw ValidationException::withMessages([
                'confirm_republish' => [
                    'This content was already published to '.$page->name.'. Set confirm_republish=true to intentionally create another post (duplicate risk).',
                ],
            ]);
        }

        $baseKey = CampaignMetaPublication::makeIdempotencyKey(
            (string) $campaign->getKey(),
            (string) $page->getKey(),
            $contentHash,
        );

        $reusable = CampaignMetaPublication::query()
            ->where('idempotency_key', $baseKey)
            ->lockForUpdate()
            ->first();

        if ($reusable !== null && in_array($reusable->status, [
            MetaPublicationStatus::Failed,
            MetaPublicationStatus::Cancelled,
        ], true) && ! $confirmRepublish) {
            $reusable->forceFill([
                'status' => MetaPublicationStatus::Queued,
                'simulated' => $simulated,
                'publish_intent' => null,
                'message_snapshot' => $message,
                'link_snapshot' => $link,
                'asset_id' => $campaign->meta_asset_id,
                'provider_post_id' => null,
                'provider' => null,
                'attempts' => 0,
                'error_code' => null,
                'error_message' => null,
                'error_retryable' => false,
                'queued_at' => now(),
                'publishing_started_at' => null,
                'published_at' => null,
                'failed_at' => null,
                'lease_expires_at' => null,
                'lease_token' => null,
                'meta' => null,
            ])->save();

            return $reusable->fresh();
        }

        $intent = null;
        $idempotency = $baseKey;
        if ($confirmRepublish && $published !== null) {
            $intent = (string) Str::ulid();
            $idempotency = CampaignMetaPublication::makeIdempotencyKey(
                (string) $campaign->getKey(),
                (string) $page->getKey(),
                $contentHash,
                $intent,
            );
        }

        try {
            return CampaignMetaPublication::query()->create([
                'store_id' => $campaign->store_id,
                'campaign_id' => $campaign->getKey(),
                'meta_page_id' => $page->getKey(),
                'status' => MetaPublicationStatus::Queued,
                'simulated' => $simulated,
                'idempotency_key' => $idempotency,
                'content_hash' => $contentHash,
                'publish_intent' => $intent,
                'message_snapshot' => $message,
                'link_snapshot' => $link,
                'asset_id' => $campaign->meta_asset_id,
                'attempts' => 0,
                'queued_at' => now(),
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueIdempotencyViolation($e)) {
                throw ValidationException::withMessages([
                    'meta' => ['A publication with this content is already in progress for '.$page->name.'.'],
                ]);
            }

            throw $e;
        }
    }

    private function isUniqueIdempotencyViolation(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'idempotency_key')
            || str_contains($message, 'campaign_meta_publications_idempotency_key')
            || (int) $e->errorInfo[1] === 1062;
    }
}
