<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Standings;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Query\GetCompetitionStandings;
use SpeedPuzzling\Web\Query\GetPublicStandingHead;
use SpeedPuzzling\Web\Query\GetStandingResults;
use SpeedPuzzling\Web\Results\CompetitionStandingOverview;
use SpeedPuzzling\Web\Results\PublicStandingSnapshot;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The guest snapshot of a standing (competition toolkit v2 S1, displays-standings.md §2.1) - the one computation behind
 * the public JSON (public_standing_state) and the displays' standings element: GetStandingResults::publicView() as a
 * guest (asGuest: no viewer, the signed-in user's blocklist and allow list ignored explicitly), so it can be shared.
 *
 * **Generation key, no stale window.** Pool `competition_standings_cache`:
 * - `gen_{cid}` - the event's generation, a random string created when absent (1 day); changed() writes a new one. It is
 *   called after the commit of every write that can change a published standing (CompetitionStandingsLiveUpdates).
 * - `snap_v1_{standingId}_{gen}` - the snapshot, SNAPSHOT_LIFETIME. A reader that read the old generation before a commit
 *   stores under the old key, which nobody asks for again - so after a signalled write nobody reads a stale snapshot;
 *   unsignalled edits (a participant renamed, a player turning private) heal within SNAPSHOT_LIFETIME.
 * - `event_{standingId}` - the standing's event (immutable, 1 day); `list_v1_{cid}_{gen}` - the event's standings.
 * - The cache failing is no failure: every read falls back to computing (a warning), nothing is ever stale for it.
 *
 * Statements: warm 0; cold: the event (1, once a day), the head (1), the rounds (1), the entries (1 - one category).
 * No instance state (readonly) - FrankenPHP worker mode needs no reset.
 *
 * @phpstan-import-type SnapshotData from PublicStandingSnapshot
 */
readonly final class PublicStandingSnapshots
{
    public const int SNAPSHOT_LIFETIME = 60;
    private const int GENERATION_LIFETIME = 86400;
    private const string VERSION = 'v1';

    public function __construct(
        private GetPublicStandingHead $getPublicStandingHead,
        private GetStandingResults $getStandingResults,
        private GetCompetitionStandings $getCompetitionStandings,
        private CacheInterface $competitionStandingsCache,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The standing's event, null for an unknown standing.
     */
    public function competitionOf(string $standingId): null|string
    {
        $key = strtolower($standingId);

        $competitionId = $this->cached('event_' . $key, function (ItemInterface $item) use ($key): string {
            $competitionId = $this->getPublicStandingHead->competitionOf($key);
            // An unknown id is asked again soon (a standing created a moment later), a known one never changes
            $item->expiresAfter($competitionId !== null ? self::GENERATION_LIFETIME : self::SNAPSHOT_LIFETIME);

            return $competitionId ?? '';
        }, fn (): string => $this->getPublicStandingHead->competitionOf($key) ?? '');

        return is_string($competitionId) && $competitionId !== '' ? $competitionId : null;
    }

    /**
     * The guest snapshot - `published: false` (no rows) for an unpublished standing, null for an unknown one.
     */
    public function forGuest(string $standingId): null|PublicStandingSnapshot
    {
        $competitionId = $this->competitionOf($standingId);

        if ($competitionId === null) {
            return null;
        }

        $key = strtolower($standingId);
        $compute = fn (): array => $this->compute($key);

        $data = $this->cached(
            sprintf('snap_%s_%s_%s', self::VERSION, $key, $this->generation($competitionId)),
            function (ItemInterface $item) use ($compute): array {
                $item->expiresAfter(self::SNAPSHOT_LIFETIME);

                return $compute();
            },
            $compute,
        );

        if (!is_array($data) || ($data['missing'] ?? false) === true || !isset($data['standingId'])) {
            return null;
        }

        /** @var SnapshotData $data */
        return PublicStandingSnapshot::fromArray($data);
    }

    /**
     * The event's standings in their order (for a display's `auto` pick) - published or not, `roundIds` as stored.
     *
     * @return list<array{id: string, name: string, slug: string, category: string, mode: string, roundIds: list<string>, published: bool, position: int}>
     */
    public function listFor(string $competitionId): array
    {
        $key = strtolower($competitionId);
        $compute = fn (): array => array_map(static fn (CompetitionStandingOverview $standing): array => [
            'id' => strtolower($standing->id),
            'name' => $standing->name,
            'slug' => $standing->slug,
            'category' => $standing->category->value,
            'mode' => $standing->mode->value,
            'roundIds' => $standing->roundIds,
            'published' => $standing->publishedAt !== null,
            'position' => $standing->position,
        ], $this->getCompetitionStandings->ofCompetition($key));

        $list = $this->cached(
            sprintf('list_%s_%s_%s', self::VERSION, $key, $this->generation($key)),
            function (ItemInterface $item) use ($compute): array {
                $item->expiresAfter(self::SNAPSHOT_LIFETIME);

                return $compute();
            },
            $compute,
        );

        if (!is_array($list)) {
            return $compute();
        }

        /** @var list<array{id: string, name: string, slug: string, category: string, mode: string, roundIds: list<string>, published: bool, position: int}> $list */
        return $list;
    }

    /**
     * The event's current generation - part of every snapshot key.
     */
    public function generation(string $competitionId): string
    {
        $generation = $this->cached('gen_' . strtolower($competitionId), static function (ItemInterface $item): string {
            $item->expiresAfter(self::GENERATION_LIFETIME);

            return self::newGeneration();
        }, self::newGeneration(...));

        return is_string($generation) && $generation !== '' ? $generation : self::newGeneration();
    }

    /**
     * Something a published standing of the event shows changed (called after the commit): a new generation, so no
     * reader gets an earlier snapshot again. The next reader computes.
     */
    public function changed(string $competitionId): void
    {
        try {
            $this->competitionStandingsCache->delete('gen_' . strtolower($competitionId));
        } catch (\Throwable $exception) {
            // Snapshots live SNAPSHOT_LIFETIME - the standing heals by then
            $this->logger->warning('Could not start a new generation of an event\'s standings', [
                'competition_id' => $competitionId,
                'exception' => $exception,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(string $standingId): array
    {
        $head = $this->getPublicStandingHead->byId($standingId);

        if ($head === null) {
            return ['missing' => true];
        }

        $now = (int) $this->clock->now()->format('Uv');

        if ($head->standing->publishedAt === null) {
            return PublicStandingSnapshot::unpublished($head, $now)->toArray();
        }

        return PublicStandingSnapshot::of(
            $head,
            $this->getStandingResults->publicView($head->standing, null, asGuest: true),
            $now,
        )->toArray();
    }

    /**
     * @param callable(ItemInterface): mixed $callback
     * @param callable(): mixed $fallback
     */
    private function cached(string $key, callable $callback, callable $fallback): mixed
    {
        $computing = false;

        try {
            return $this->competitionStandingsCache->get($key, static function (ItemInterface $item) use ($callback, &$computing): mixed {
                $computing = true;

                return $callback($item);
            });
        } catch (\Throwable $exception) {
            // The computation's own failure is not the cache's - it would fail the same way again
            if ($computing) {
                throw $exception;
            }

            $this->logger->warning('The standings cache failed - computed without it', [
                'key' => $key,
                'exception' => $exception,
            ]);

            return $fallback();
        }
    }

    private static function newGeneration(): string
    {
        return bin2hex(random_bytes(4));
    }
}
