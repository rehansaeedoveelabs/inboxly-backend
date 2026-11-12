<?php

namespace App\Actions\Meta;

use App\Enums\MetaPublicationStatus;
use App\Models\CampaignMetaPublication;
use App\Models\MetaConnection;
use App\Support\Meta\Contracts\MetaProvider;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Safe recovery for outcome_unknown using conservative Page feed evidence.
 *
 * Meta feed limitations:
 * - Feed listing is recent-only and may omit older posts.
 * - Identical message text can appear from unrelated posts.
 * - Destination links are often rewritten; attachment URLs may not equal the snapshot.
 * - Therefore message-only matches never prove this attempt succeeded.
 * - A unique match requires message + time window (and link agreement when comparable).
 * - No match does not prove failure — the post may exist outside the fetched window.
 */
class ReconcileMetaPublication
{
    public function __construct(
        private readonly MetaProvider $provider,
        private readonly RefreshMetaPageToken $refreshPageToken,
    ) {}

    public function handle(CampaignMetaPublication $publication): CampaignMetaPublication
    {
        if ($publication->status !== MetaPublicationStatus::OutcomeUnknown) {
            throw ValidationException::withMessages([
                'publication' => ['Only publications with an unknown outcome can be reconciled.'],
            ]);
        }

        $page = $publication->metaPage;
        $connection = MetaConnection::query()
            ->where('store_id', $publication->store_id)
            ->first();

        if ($page === null || $connection === null) {
            throw ValidationException::withMessages([
                'meta' => ['Meta Page or connection is missing.'],
            ]);
        }

        $page = $this->refreshPageToken->handle($connection, $page);

        $posts = $this->provider->listRecentPagePosts(
            (string) $page->meta_page_id,
            (string) $page->page_access_token,
            25,
        );

        $candidates = $this->matchingCandidates($publication, $posts);

        if (count($candidates) === 1) {
            $post = $candidates[0];
            $publication->forceFill([
                'status' => MetaPublicationStatus::Published,
                'provider_post_id' => $post['id'],
                'provider' => $publication->simulated ? 'fake' : 'meta',
                'error_code' => null,
                'error_message' => null,
                'published_at' => $this->parseCreatedTime($post['created_time'] ?? null) ?? now(),
                'meta' => array_merge($publication->meta ?? [], [
                    'reconciled' => true,
                    'reconciled_at' => now()->toIso8601String(),
                    'reconcile_evidence' => 'message_time'.(isset($post['link_agreed']) && $post['link_agreed'] ? '_link' : ''),
                    'feed_limitations' => 'Meta feed proves at most a recent matching post; not a delivery receipt.',
                ]),
            ])->save();

            return $publication->fresh();
        }

        if (count($candidates) > 1) {
            $publication->forceFill([
                'error_code' => 'ambiguous_feed_match',
                'error_message' => 'Multiple recent Page posts match this attempt. Confirm on Facebook, then resolve deliberately.',
            ])->save();

            return $publication->fresh();
        }

        $publication->forceFill([
            'error_code' => 'not_found_on_page',
            'error_message' => 'No uniquely matching recent Page post was found. This does not prove failure. Resolve deliberately if you confirmed the outcome on Facebook.',
            'meta' => array_merge($publication->meta ?? [], [
                'reconcile_attempted_at' => now()->toIso8601String(),
                'feed_limitations' => 'No match in recent feed is inconclusive; posts may be outside the window or omitted.',
            ]),
        ])->save();

        return $publication->fresh();
    }

    /**
     * @param  list<array{id: string, message: string|null, created_time: string|null, permalink_url?: string|null, link?: string|null}>  $posts
     * @return list<array{id: string, message: string|null, created_time: string|null, link_agreed?: bool}>
     */
    private function matchingCandidates(CampaignMetaPublication $publication, array $posts): array
    {
        $needle = trim((string) $publication->message_snapshot);
        if ($needle === '') {
            return [];
        }

        $windowStart = ($publication->publishing_started_at ?? $publication->queued_at ?? $publication->created_at)
            ?->copy()
            ->subMinutes(5);
        $windowEnd = ($publication->publishing_started_at ?? $publication->queued_at ?? $publication->created_at)
            ?->copy()
            ->addMinutes(45);

        if ($windowStart === null || $windowEnd === null) {
            return [];
        }

        $expectedLink = is_string($publication->link_snapshot) ? trim($publication->link_snapshot) : '';
        $candidates = [];

        foreach ($posts as $post) {
            $message = isset($post['message']) ? trim((string) $post['message']) : '';
            if ($message === '' || $message !== $needle) {
                continue;
            }

            $created = $this->parseCreatedTime($post['created_time'] ?? null);
            if ($created === null || $created->lt($windowStart) || $created->gt($windowEnd)) {
                // Message-only match without a time window is insufficient evidence.
                continue;
            }

            $linkAgreed = false;
            if ($expectedLink !== '') {
                $candidateLink = null;
                if (isset($post['link']) && is_string($post['link']) && $post['link'] !== '') {
                    $candidateLink = $post['link'];
                }
                if ($candidateLink !== null && $this->linksComparable($expectedLink, $candidateLink)) {
                    $linkAgreed = true;
                } elseif ($candidateLink !== null) {
                    // Explicit conflicting destination — reject this candidate.
                    continue;
                }
                // Missing attachment link on the feed item: still allow message+time uniqueness.
            }

            $candidates[] = array_merge($post, ['link_agreed' => $linkAgreed]);
        }

        return $candidates;
    }

    private function parseCreatedTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function linksComparable(string $expected, string $candidate): bool
    {
        $normalize = static function (string $url): string {
            $parts = parse_url($url);
            if ($parts === false || empty($parts['host'])) {
                return strtolower(trim($url));
            }
            $host = strtolower($parts['host']);
            $path = $parts['path'] ?? '';
            $query = $parts['query'] ?? '';

            return $host.$path.($query !== '' ? '?'.$query : '');
        };

        return $normalize($expected) === $normalize($candidate)
            || str_contains($normalize($candidate), $normalize($expected))
            || str_contains($normalize($expected), $normalize($candidate));
    }
}
