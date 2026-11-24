<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnalyticsStudio;

use Closure;
use SpeedPuzzling\Web\Exceptions\AnalyticsStudioNotFound;
use SpeedPuzzling\Web\Query\GetAnalyticsStudioEntrants;
use SpeedPuzzling\Web\Query\OfficialEntrantsView;
use SpeedPuzzling\Web\Results\AnalyticsStudioContext;
use SpeedPuzzling\Web\Results\AnalyticsStudioEntrant;
use SpeedPuzzling\Web\Results\AnalyticsStudioMember;
use SpeedPuzzling\Web\Results\RoundPuzzleSlot;
use SpeedPuzzling\Web\Services\RoundStopwatchLiveUpdates;
use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;
use SpeedPuzzling\Web\Value\RoundClock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The analytics studio's `state` answer (analytics-studio.md §3.1, R2): the round with its stopwatch, the puzzle slots
 * by the public reveal rule (a hidden slot is its label only), the entrants and the ranking of their recorded results
 * (AnalyticsStudioLiveResults - live while the round runs, the official rows once published). The same for everybody -
 * no viewer.
 *
 * Statements: the round and its visible puzzles (2, always - the cache key is made of them), then the entrants and,
 * for a published round, its official rows (OfficialEntrantsView) - kept 2 s in `analytics_studio_cache` under the
 * round, its stopwatch state hash (RoundClock::stateHash()), the visible hash, the published moment, the live results
 * switch and - only on a labelled round - a hash of its table labels, never past the next reveal moment (not kept at all when that is under 2 s away). `serverNowMs` is stamped on every answer outside
 * the cache. A result recorded, edited or cleared reaches the page within those 2 s: the write's studio signal
 * (AnalyticsStudioSignals) makes the page ask after SIGNAL_SETTLE_MS (2.3 s), when the entry has expired - one
 * computation per 2 s per round under any write rate, no key needs the results.
 *
 * Every computed state marks the round as watched (AnalyticsStudioWatch, 180 s - no statement), so its writes publish the
 * public data-free studio signal (AnalyticsStudioSignals) the page follows next to the stopwatch topic (`mercure.topics`).
 */
readonly final class AnalyticsStudioStateBuilder
{
    public const int TTL_SECONDS = 2;
    public const string IMAGE_PRESET = 'puzzle_medium';

    public function __construct(
        private AnalyticsStudioContextLoader $contextLoader,
        private GetAnalyticsStudioEntrants $getAnalyticsStudioEntrants,
        private OfficialEntrantsView $officialEntrantsView,
        private ImageThumbnailTwigExtension $imageThumbnail,
        private CacheInterface $analyticsStudioCache,
        private AnalyticsStudioWatch $watch,
        #[Autowire('%env(MERCURE_PUBLIC_URL)%')]
        private string $mercurePublicUrl,
    ) {
    }

    /**
     * @return array<string, mixed>
     * @throws AnalyticsStudioNotFound
     */
    public function build(string $roundId): array
    {
        return $this->answer($this->contextLoader->load($roundId), false);
    }

    /**
     * The staff state (FU-ST, `analytics_studio_staff_state`): `$isStaff` (the round's competition id => may the viewer
     * enter its results - CompetitionResultsEntryVoter) decides once the round is known; staff get the live ranking of an
     * unpublished round whatever the live results setting, anybody else exactly build()'s public answer. The staff
     * answer is the same for all of an event's staff (no viewer-dependent read - see GetAnalyticsStudioEntrants), kept
     * under its own `staff-state.` keys: it never fills or reads the public entry.
     *
     * @param Closure(string): bool $isStaff
     * @return array<string, mixed>
     * @throws AnalyticsStudioNotFound
     */
    public function buildForViewer(string $roundId, Closure $isStaff): array
    {
        $context = $this->contextLoader->load($roundId);

        return $this->answer($context, $isStaff($context->round->competitionId));
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(AnalyticsStudioContext $context, bool $staff): array
    {
        $round = $context->round;
        $nowMs = RoundClock::milliseconds($context->now);
        $stopwatch = $round->stopwatch($context->now);

        $key = implode('.', [
            $staff ? 'staff-state' : 'state',
            $round->id,
            $stopwatch->clock->stateHash(),
            $context->visibleHash,
            $round->resultsPublishedAt !== null ? (string) RoundClock::milliseconds($round->resultsPublishedAt) : 'u',
            $round->liveResults ? 'l' : 'n',
        ]);

        // A labelled round's table names are in the answer (entrants' `tableLabel`): a changed list is another entry at
        // once - an unlabelled round's key stays as it was
        if ($round->tableLabels !== null) {
            $key .= '.t' . substr(sha1(implode("\n", $round->tableLabels->labels)), 0, 12);
        }

        $next = $context->puzzles->nextRevealAtMs;
        $ttl = $next === null ? self::TTL_SECONDS : intdiv($next - $nowMs, 1000);

        $payload = $ttl >= self::TTL_SECONDS
            ? $this->analyticsStudioCache->get($key, function (ItemInterface $item) use ($context, $staff): array {
                $item->expiresAfter(self::TTL_SECONDS);

                return $this->compute($context, $staff);
            })
            : $this->compute($context, $staff);

        /** @var array<string, mixed> $payload */
        return ['serverNowMs' => $nowMs, ...$payload];
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(AnalyticsStudioContext $context, bool $staff): array
    {
        $round = $context->round;
        $this->watch->markWatched($round->id);

        if ($staff) {
            $this->watch->markStaffWatched($round->id);
        }

        $entrants = $this->getAnalyticsStudioEntrants->forRound($round);
        // As a guest: no viewer's blocklist or allow list may reach an answer that is shared (FU-ST - the staff state runs
        // on the stateful firewall; the public one is stateless and the same either way)
        $official = $round->published()
            ? $this->officialEntrantsView->forRounds([$round->id => $round->category], null, asGuest: true)[$round->id]
            : null;

        $roundJson = [
            'id' => $round->id,
            'name' => $round->name,
            'slug' => $round->slug,
            'category' => $round->category->value,
            'startsAtMs' => RoundClock::milliseconds($round->startsAt),
            'minutesLimit' => $round->minutesLimit,
            'stopwatch' => $round->stopwatch($context->now)->jsonSerialize(),
            'published' => $round->published(),
        ];

        if ($round->isPick()) {
            $roundJson['puzzleFormat'] = ['kind' => 'pick', 'toSolve' => $round->puzzlesToSolve];
        }

        return [
            'round' => $roundJson,
            'event' => [
                'name' => $round->eventName,
                'slug' => $round->eventSlug,
                'seriesName' => $round->seriesName,
                'seriesSlug' => $round->seriesSlug,
            ],
            'slots' => array_map(fn (RoundPuzzleSlot $slot): array => $this->slot($slot), $context->puzzles->slots),
            'visibleHash' => $context->visibleHash,
            'nextRevealAtMs' => $context->puzzles->nextRevealAtMs,
            'cutOffMs' => $context->cutOffMs(),
            'entrants' => array_map(static fn (AnalyticsStudioEntrant $entrant): array => self::entrant($entrant), $entrants),
            'results' => AnalyticsStudioLiveResults::of($round, $entrants, $context->puzzles, $official, $staff),
            'mercure' => [
                'hubUrl' => $this->mercurePublicUrl,
                'topics' => [RoundStopwatchLiveUpdates::topic($round->id), AnalyticsStudioSignals::topic($round->id)],
            ],
        ];
    }

    /**
     * A slot: its label always, the puzzle only from RoundPuzzlesVisibleNow - a hidden one is `puzzle: null`.
     *
     * @return array{key: string, position: int, label: string, revealsAtMs: null|int, puzzle: null|array{key: string, name: string, brand: null|string, pieces: int, imageUrl: null|string, imageLater?: true}}
     */
    private function slot(RoundPuzzleSlot $slot): array
    {
        $puzzle = $slot->puzzle;

        return [
            'key' => strtolower($slot->roundPuzzleId),
            'position' => $slot->position,
            'label' => $slot->label,
            'revealsAtMs' => $slot->revealsAt !== null ? RoundClock::milliseconds($slot->revealsAt) : null,
            'puzzle' => $puzzle === null ? null : [
                'key' => strtolower($slot->roundPuzzleId),
                'name' => $puzzle->puzzleName,
                'brand' => $puzzle->manufacturerName,
                'pieces' => $puzzle->piecesCount,
                'imageUrl' => $puzzle->puzzleImage !== null ? $this->imageThumbnail->thumbnailUrl($puzzle->puzzleImage, self::IMAGE_PRESET) : null,
                // Its picture waits (an unboxed puzzle before the results) - the key only then; a puzzle without a picture
                // is just that (spws-demo-ux-leftovers.md §7)
                ...($puzzle->imageHidden ? ['imageLater' => true] : []),
            ],
        ];
    }

    /**
     * Built field by field - never a serialized OfficialEntrantsView object (R16): a private or unlinked person is
     * the organiser's name only, a public profile adds its profile path and code.
     *
     * A labelled round's entrant also carries `tableLabel` (table-labels-and-plates.md) - only when the list names its table.
     *
     * @return array{key: string, isTeam: bool, teamName: null|string, table: null|int, tableLabel?: string, members: list<array{name: string, country: null|string, profile: null|string, code: null|string, analysable: bool}>}
     */
    public static function entrant(AnalyticsStudioEntrant $entrant): array
    {
        $json = [
            'key' => $entrant->key(),
            'isTeam' => $entrant->isTeam(),
            'teamName' => $entrant->teamName,
            'table' => $entrant->tableNumber,
        ];

        // Next to `table`; an unlabelled entrant keeps its exact shape
        if ($entrant->tableLabel !== null) {
            $json['tableLabel'] = $entrant->tableLabel;
        }

        $json['members'] = array_map(static fn (AnalyticsStudioMember $member): array => [
            'name' => $member->entrant->playerName,
            'country' => $member->entrant->playerCountry?->name,
            'profile' => $member->analysablePlayerId() !== null ? AnalyticsStudioProfile::path($member->analysablePlayerId()) : null,
            'code' => $member->playerCode,
            'analysable' => $member->analysable(),
        ], $entrant->members);

        return $json;
    }
}
