<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnalyticsStudio;

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
 * by the public reveal rule (a hidden slot is its label only), the entrants and the results the public may see
 * (AnalyticsStudioLiveResults). The same for everybody - no viewer.
 *
 * Statements: the round and its visible puzzles (2, always - the cache key is made of them), then the entrants and,
 * for a published round, its official rows (OfficialEntrantsView) - kept 2 s in `analytics_studio_cache` under the
 * round, its stopwatch state hash (RoundClock::stateHash()), the visible hash and the published/live switches, never past
 * the next reveal moment (not kept at all when that is under 2 s away). `serverNowMs` is stamped on every answer
 * outside the cache.
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
        $context = $this->contextLoader->load($roundId);
        $round = $context->round;
        $nowMs = RoundClock::milliseconds($context->now);
        $stopwatch = $round->stopwatch($context->now);

        $key = implode('.', [
            'state',
            $round->id,
            $stopwatch->clock->stateHash(),
            $context->visibleHash,
            $round->resultsPublishedAt !== null ? (string) RoundClock::milliseconds($round->resultsPublishedAt) : 'u',
            $round->liveResults ? 'l' : 'n',
        ]);

        $next = $context->puzzles->nextRevealAtMs;
        $ttl = $next === null ? self::TTL_SECONDS : intdiv($next - $nowMs, 1000);

        $payload = $ttl >= self::TTL_SECONDS
            ? $this->analyticsStudioCache->get($key, function (ItemInterface $item) use ($context): array {
                $item->expiresAfter(self::TTL_SECONDS);

                return $this->compute($context);
            })
            : $this->compute($context);

        /** @var array<string, mixed> $payload */
        return ['serverNowMs' => $nowMs, ...$payload];
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(AnalyticsStudioContext $context): array
    {
        $round = $context->round;
        $entrants = $this->getAnalyticsStudioEntrants->forRound($round);
        $official = $round->published() ? $this->officialEntrantsView->forRound($round->id, $round->category, null) : null;

        $roundJson = [
            'id' => $round->id,
            'name' => $round->name,
            'slug' => $round->slug,
            'category' => $round->category->value,
            'startsAtMs' => RoundClock::milliseconds($round->startsAt),
            'minutesLimit' => $round->minutesLimit,
            'stopwatch' => $round->stopwatch($context->now)->jsonSerialize(),
            'published' => $round->published(),
            'liveResults' => $round->liveResults,
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
            'results' => AnalyticsStudioLiveResults::of($round, $entrants, $context->puzzles, $official),
            'mercure' => [
                'hubUrl' => $this->mercurePublicUrl,
                'topics' => [RoundStopwatchLiveUpdates::topic($round->id)],
            ],
        ];
    }

    /**
     * A slot: its label always, the puzzle only from RoundPuzzlesVisibleNow - a hidden one is `puzzle: null`.
     *
     * @return array{key: string, position: int, label: string, revealsAtMs: null|int, puzzle: null|array{key: string, name: string, brand: null|string, pieces: int, imageUrl: null|string}}
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
            ],
        ];
    }

    /**
     * Built field by field - never a serialized OfficialEntrantsView object (R16): a private or unlinked person is
     * the organiser's name only, a public profile adds its profile path and code.
     *
     * @return array{key: string, isTeam: bool, teamName: null|string, table: null|int, members: list<array{name: string, country: null|string, profile: null|string, code: null|string, analysable: bool}>}
     */
    public static function entrant(AnalyticsStudioEntrant $entrant): array
    {
        return [
            'key' => $entrant->key(),
            'isTeam' => $entrant->isTeam(),
            'teamName' => $entrant->teamName,
            'table' => $entrant->tableNumber,
            'members' => array_map(static fn (AnalyticsStudioMember $member): array => [
                'name' => $member->entrant->playerName,
                'country' => $member->entrant->playerCountry?->name,
                'profile' => $member->analysablePlayerId() !== null ? AnalyticsStudioProfile::path($member->analysablePlayerId()) : null,
                'code' => $member->playerCode,
                'analysable' => $member->analysable(),
            ], $entrant->members),
        ];
    }
}
