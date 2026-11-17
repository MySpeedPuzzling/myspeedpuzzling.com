<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnalyticsStudio;

use SpeedPuzzling\Web\Exceptions\AnalyticsStudioNotFound;
use SpeedPuzzling\Web\Query\GetAnalyticsStudioEntrantHistory;
use SpeedPuzzling\Web\Query\GetAnalyticsStudioEntrants;
use SpeedPuzzling\Web\Query\GetAnalyticsStudioLineupHistory;
use SpeedPuzzling\Web\Query\GetAnalyticsStudioPlayerFacts;
use SpeedPuzzling\Web\Query\GetAnalyticsStudioSimilarHistory;
use SpeedPuzzling\Web\Results\AnalyticsStudioAttempt;
use SpeedPuzzling\Web\Results\AnalyticsStudioContext;
use SpeedPuzzling\Web\Results\AnalyticsStudioEntrant;
use SpeedPuzzling\Web\Results\AnalyticsStudioPlayerFacts;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\SkillTier;
use SpeedPuzzling\Web\Value\TeamComposition;
use stdClass;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The analytics studio's `analytics` answer (analytics-studio.md §3.2, R1, R3, R4): the visible puzzles' community
 * statistics and every entrant's numbers - per member their numbers as of now (rating, place, skill, totals) and their
 * history before the round on its visible puzzles and on similar ones, per pair/team the exact line-up's history. No
 * predictions (R3: they come per entrant on screen - AnalyticsStudioPredictions). Only visible slots appear as keys, so
 * the key set never depends on a hidden puzzle; a member without public MySpeedPuzzling history (unlinked or private -
 * indistinguishable) is `{}`.
 *
 * Statements: the round, its visible puzzles and the entrants (3, always - the cache key is made of them), then when
 * cold the puzzle statistics (AnalyticsStudioPuzzleStatsCache, its own 10 minutes), the history on the round's puzzles,
 * the members' facts, the similar-puzzle summary and - pair/team rounds - the line-ups. The whole payload is kept
 * 10 minutes under the round, the visible hash, the history cut-off and a hash of the entrants' analysable members and
 * opt-outs: a reveal, a stopwatch start or a new entrant is a new key.
 */
readonly final class AnalyticsStudioAnalyticsBuilder
{
    public const int TTL_SECONDS = 600;

    public function __construct(
        private AnalyticsStudioContextLoader $contextLoader,
        private GetAnalyticsStudioEntrants $getAnalyticsStudioEntrants,
        private AnalyticsStudioPuzzleStatsCache $puzzleStatsCache,
        private GetAnalyticsStudioEntrantHistory $getAnalyticsStudioEntrantHistory,
        private GetAnalyticsStudioPlayerFacts $getAnalyticsStudioPlayerFacts,
        private GetAnalyticsStudioSimilarHistory $getAnalyticsStudioSimilarHistory,
        private GetAnalyticsStudioLineupHistory $getAnalyticsStudioLineupHistory,
        private CacheInterface $analyticsStudioCache,
    ) {
    }

    /**
     * @return array<string, mixed>
     * @throws AnalyticsStudioNotFound
     */
    public function build(string $roundId): array
    {
        $context = $this->contextLoader->load($roundId);
        $entrants = $this->getAnalyticsStudioEntrants->forRound($context->round);
        $key = implode('.', ['analytics', $context->round->id, $context->visibleHash, $context->cutOffMs(), self::entrantsHash($entrants)]);

        /** @var array<string, mixed> $payload */
        $payload = $this->analyticsStudioCache->get($key, function (ItemInterface $item) use ($context, $entrants): array {
            $item->expiresAfter(self::TTL_SECONDS);

            return $this->compute($context, $entrants);
        });

        return $payload;
    }

    /**
     * What a cached answer depends on besides the round and its visible puzzles: who is analysable in which entry, and
     * their opt-outs.
     *
     * @param list<AnalyticsStudioEntrant> $entrants
     */
    public static function entrantsHash(array $entrants): string
    {
        $parts = [];

        foreach ($entrants as $entrant) {
            $members = [];
            foreach ($entrant->members as $member) {
                $members[] = ($member->analysablePlayerId() ?? '-') . ($member->timePredictionsOptedOut ? 'p' : '') . ($member->rankingOptedOut ? 'r' : '');
            }
            $parts[] = $entrant->key() . '=' . implode('+', $members);
        }

        sort($parts);

        return substr(sha1(implode(',', $parts)), 0, 16);
    }

    /**
     * @param list<AnalyticsStudioEntrant> $entrants
     * @return array<string, mixed>
     */
    private function compute(AnalyticsStudioContext $context, array $entrants): array
    {
        $stats = $this->puzzleStatsCache->of($context->visiblePuzzleIds(), $context->round->category);
        $collected = $this->collect($context, $entrants, $stats);

        // Who of the round holds a place in a top ten: an analysable member's entry
        $entryOfPlayer = [];
        foreach ($entrants as $entrant) {
            foreach ($entrant->analysablePlayerIds() as $playerId) {
                $entryOfPlayer[$playerId] = $entrant->key();
            }
        }

        $puzzles = [];
        foreach ($context->visible as $slotKey => $puzzle) {
            $puzzleStats = $stats[strtolower($puzzle->puzzleId)] ?? null;

            if ($puzzleStats !== null) {
                $puzzles[$slotKey] = self::puzzle($puzzleStats, $entryOfPlayer, $context->round->category);
            }
        }

        return [
            'visibleHash' => $context->visibleHash,
            'cutOffMs' => $context->cutOffMs(),
            'puzzles' => $puzzles === [] ? new stdClass() : $puzzles,
            'entrants' => $collected['rows'] === [] ? new stdClass() : $collected['rows'],
        ];
    }

    /**
     * The analytics rows of these entrants (one entrant for the entrant detail) - with the attempts they were folded
     * from, so the detail lists them without asking again.
     *
     * @param list<AnalyticsStudioEntrant> $entrants
     * @param array<string, array{solo: array{bests: list<int>, firstTries: int, firstTryMedian: null|int, fastestFirstTry: null|int}, group: mixed, top: mixed, timesLogged: int, difficulty: mixed}> $stats
     * @return array{rows: array<string, array<string, mixed>>, attempts: array<string, list<AnalyticsStudioAttempt>>, lineups: array<string, list<AnalyticsStudioAttempt>>}
     */
    public function collect(AnalyticsStudioContext $context, array $entrants, array $stats): array
    {
        $round = $context->round;
        $memberIds = [];
        $lineupKeys = [];

        foreach ($entrants as $entrant) {
            foreach ($entrant->analysablePlayerIds() as $playerId) {
                $memberIds[$playerId] = true;
            }

            $lineupKey = self::lineupKey($entrant);
            if ($lineupKey !== null) {
                $lineupKeys[$lineupKey] = true;
            }
        }

        $memberIds = array_keys($memberIds);
        $puzzleIds = $context->visiblePuzzleIds();
        $cutOff = $round->historyCutOff();

        $attempts = $this->getAnalyticsStudioEntrantHistory->attempts($puzzleIds, $memberIds, $cutOff, $round->id);
        $facts = $this->getAnalyticsStudioPlayerFacts->of($memberIds, $context->visiblePiecesCounts());
        $similar = $this->getAnalyticsStudioSimilarHistory->summary($memberIds, $context->visible, $cutOff, $round->id, $context->now);
        $lineups = $round->category !== RoundCategory::Solo
            ? $this->getAnalyticsStudioLineupHistory->of(array_keys($lineupKeys), $puzzleIds, $cutOff, $round->id)
            : [];

        $attemptsByMember = [];
        foreach ($attempts as $attempt) {
            $attemptsByMember[$attempt->memberId][] = $attempt;
        }

        $rows = [];

        foreach ($entrants as $entrant) {
            $members = [];

            foreach ($entrant->members as $member) {
                $playerId = $member->analysablePlayerId();

                if ($playerId === null) {
                    $members[] = new stdClass();
                    continue;
                }

                $members[] = $this->member(
                    $context,
                    $facts[$playerId] ?? null,
                    $attemptsByMember[$playerId] ?? [],
                    $similar[$playerId] ?? [],
                    $stats,
                    $member->rankingOptedOut,
                );
            }

            $row = ['members' => $members];

            if ($entrant->isTeam()) {
                $lineupKey = self::lineupKey($entrant);
                $row['lineup'] = $lineupKey === null ? null : ['perPuzzle' => self::perPuzzle($context, $lineups[$lineupKey] ?? [], null)];
            }

            $rows[$entrant->key()] = $row;
        }

        return ['rows' => $rows, 'attempts' => $attemptsByMember, 'lineups' => $lineups];
    }

    /**
     * The exact line-up's key (TeamComposition) of a pair/team whose every member is analysable - else null: a line-up
     * with an unlinked or private member has no line-up history.
     */
    public static function lineupKey(AnalyticsStudioEntrant $entrant): null|string
    {
        if ($entrant->isTeam() === false || count($entrant->members) < 2) {
            return null;
        }

        $playerIds = $entrant->analysablePlayerIds();

        if (count($playerIds) !== count($entrant->members)) {
            return null;
        }

        return TeamComposition::keyFromMemberKeys(array_map('strtolower', $playerIds));
    }

    /**
     * @param list<AnalyticsStudioAttempt> $attempts
     * @param array<string, array{sizeCount: int, sizeMedian: null|int, sizeBest: null|int, brandCount: int, brandMedian: null|int, brandBest: null|int}> $similar
     * @param array<string, array{solo: array{bests: list<int>, firstTries: int, firstTryMedian: null|int, fastestFirstTry: null|int}, group: mixed, top: mixed, timesLogged: int, difficulty: mixed}> $stats
     * @return array<string, mixed>
     */
    private function member(
        AnalyticsStudioContext $context,
        null|AnalyticsStudioPlayerFacts $facts,
        array $attempts,
        array $similar,
        array $stats,
        bool $rankingOptedOut,
    ): array {
        // Skill tiers as the public API's tokens: at each visible puzzle's piece count - before any reveal at 500 only
        // (no piece count implied)
        $skill = null;

        if ($rankingOptedOut === false) {
            $skill = [];

            if ($context->visible === []) {
                $skill['500'] = self::skillToken($facts?->skillTiers[GetAnalyticsStudioPlayerFacts::RATING_PIECES] ?? null);
            }

            foreach ($context->visible as $slotKey => $puzzle) {
                $skill[$slotKey] = self::skillToken($facts?->skillTiers[$puzzle->piecesCount] ?? null);
            }
        }

        $member = [
            'rating' => $facts?->rating,
            'ratingPlace' => $facts?->ratingPlace,
            'solvedTotal' => $facts?->solvedTotal ?? 0,
            'best500' => $facts?->best500Seconds,
            'rankingOptedOut' => $rankingOptedOut,
            'skill' => $skill,
        ];

        if ($context->visible !== []) {
            $member['perPuzzle'] = self::perPuzzle($context, $attempts, $stats);
            $member['similar'] = [];

            foreach ($context->visible as $slotKey => $puzzle) {
                $member['similar'][$slotKey] = $similar[$slotKey] ?? [
                    'sizeCount' => 0, 'sizeMedian' => null, 'sizeBest' => null,
                    'brandCount' => 0, 'brandMedian' => null, 'brandBest' => null,
                ];
            }
        }

        return $member;
    }

    /**
     * Per visible slot: the solo history (with the best time's place among the puzzle's solvers) and, apart, the
     * history as a member of any pair/team. For a line-up ($stats null): its own history, no place.
     *
     * @param list<AnalyticsStudioAttempt> $attempts
     * @param null|array<string, array{solo: array{bests: list<int>, firstTries: int, firstTryMedian: null|int, fastestFirstTry: null|int}, group: mixed, top: mixed, timesLogged: int, difficulty: mixed}> $stats
     * @return array<string, array<string, mixed>>
     */
    private static function perPuzzle(AnalyticsStudioContext $context, array $attempts, null|array $stats): array
    {
        $perPuzzle = [];

        foreach ($context->visible as $slotKey => $puzzle) {
            $puzzleId = strtolower($puzzle->puzzleId);
            $solo = [];
            $group = [];

            foreach ($attempts as $attempt) {
                if ($attempt->puzzleId !== $puzzleId) {
                    continue;
                }

                if ($stats === null || $attempt->isGroup() === false) {
                    $solo[] = $attempt;
                } else {
                    $group[] = $attempt;
                }
            }

            $summary = AnalyticsStudioAttempts::summary($solo);

            if ($stats !== null) {
                $bests = $stats[$puzzleId]['solo']['bests'] ?? [];
                $summary['placeOnPuzzle'] = $summary['bestSeconds'] !== null && $bests !== []
                    ? AnalyticsStudioDistribution::placeOf($bests, $summary['bestSeconds'])
                    : null;

                $groupSummary = AnalyticsStudioAttempts::summary($group);
                $summary['asGroup'] = $group === [] ? null : [
                    'solves' => $groupSummary['solves'],
                    'bestSeconds' => $groupSummary['bestSeconds'],
                ];
            }

            $perPuzzle[$slotKey] = $summary;
        }

        return $perPuzzle;
    }

    private static function skillToken(null|int $tier): null|string
    {
        return $tier !== null ? SkillTier::tryFrom($tier)?->toApiValue() : null;
    }

    /**
     * @param array{solo: array{bests: list<int>, firstTries: int, firstTryMedian: null|int, fastestFirstTry: null|int}, group: null|array{bests: list<int>, firstTries: int, firstTryMedian: null|int, fastestFirstTry: null|int}, top: list<array{place: int, seconds: int, playerId: null|string, name: null|string, code: null|string, country: null|string, private: bool}>, timesLogged: int, difficulty: null|array{score: null|float, tier: null|string, confidence: string}} $stats
     * @param array<string, string> $entryOfPlayer
     * @return array<string, mixed>
     */
    private static function puzzle(array $stats, array $entryOfPlayer, RoundCategory $category): array
    {
        $bests = $stats['solo']['bests'];

        $puzzle = [
            'solvers' => count($bests),
            'timesLogged' => $stats['timesLogged'],
            'medianSeconds' => AnalyticsStudioDistribution::percentile($bests, 0.5),
            'fastestSeconds' => $bests[0] ?? null,
            'percentiles' => AnalyticsStudioDistribution::percentiles($bests),
            'histogram' => AnalyticsStudioDistribution::histogram($bests),
            'firstTries' => [
                'count' => $stats['solo']['firstTries'],
                'medianSeconds' => $stats['solo']['firstTryMedian'],
                'fastestSeconds' => $stats['solo']['fastestFirstTry'],
            ],
            'difficulty' => $stats['difficulty'],
            // A private record holder: no name, country or profile
            'top' => array_map(static fn (array $row): array => [
                'place' => $row['place'],
                'name' => $row['name'],
                'country' => $row['country'],
                'profile' => $row['playerId'] !== null ? AnalyticsStudioProfile::path($row['playerId']) : null,
                'seconds' => $row['seconds'],
                'inRound' => $row['playerId'] !== null ? ($entryOfPlayer[$row['playerId']] ?? null) : null,
            ], $stats['top']),
        ];

        if ($category !== RoundCategory::Solo) {
            $groupBests = $stats['group']['bests'] ?? [];
            // The round category's own community (pairs or teams, tracker-less imported results included)
            $puzzle['group'] = [
                'solvers' => count($groupBests),
                'medianSeconds' => AnalyticsStudioDistribution::percentile($groupBests, 0.5),
            ];
        }

        return $puzzle;
    }
}
