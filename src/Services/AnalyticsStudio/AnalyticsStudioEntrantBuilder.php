<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnalyticsStudio;

use SpeedPuzzling\Web\Exceptions\AnalyticsStudioNotFound;
use SpeedPuzzling\Web\Query\GetAnalyticsStudioEntrants;
use SpeedPuzzling\Web\Query\GetAnalyticsStudioSimilarHistory;
use SpeedPuzzling\Web\Query\OfficialEntrantsView;
use SpeedPuzzling\Web\Query\RoundPuzzlesVisibleNow;
use SpeedPuzzling\Web\Results\AnalyticsStudioAttempt;
use SpeedPuzzling\Web\Results\AnalyticsStudioContext;
use SpeedPuzzling\Web\Results\AnalyticsStudioEntrant;
use SpeedPuzzling\Web\Results\AnalyticsStudioEventRound;
use SpeedPuzzling\Web\Results\PublishedEntryPuzzle;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundClock;
use SpeedPuzzling\Web\Value\UnfinishedPiecesCount;
use stdClass;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The analytics studio's `entrant` answer (analytics-studio.md §3.3, R1, R3, R19): the entry's analytics row (the same
 * as in `analytics`) plus, per analysable member, every earlier attempt on each visible puzzle, the latest similar
 * solves, the recent form at each visible piece count and the time prediction (AnalyticsStudioPredictions - solo, the
 * shown model); a pair/team's line-up attempts; the entry's published results in the event's other rounds
 * (OfficialEntrantsView, published rounds only).
 *
 * Statements: the round, its visible puzzles and the entrants (3, always), then when cold the puzzle statistics (their
 * own cache), the attempts, the facts and the similar summary (AnalyticsStudioAnalyticsBuilder::collect()), the line-up
 * (pairs/teams), the similar solves, the recent form and the earlier rounds (0-2) - kept 5 minutes per entry, visible
 * hash and history cut-off - and per member without a cached prediction GetPlayerPredictions' own statements (15 minutes).
 */
readonly final class AnalyticsStudioEntrantBuilder
{
    public const int TTL_SECONDS = 300;

    public function __construct(
        private AnalyticsStudioContextLoader $contextLoader,
        private GetAnalyticsStudioEntrants $getAnalyticsStudioEntrants,
        private AnalyticsStudioPuzzleStatsCache $puzzleStatsCache,
        private AnalyticsStudioAnalyticsBuilder $analyticsBuilder,
        private GetAnalyticsStudioSimilarHistory $getAnalyticsStudioSimilarHistory,
        private OfficialEntrantsView $officialEntrantsView,
        private RoundPuzzlesVisibleNow $roundPuzzlesVisibleNow,
        private AnalyticsStudioPredictions $predictions,
        private CacheInterface $analyticsStudioCache,
    ) {
    }

    /**
     * @return array<string, mixed>
     * @throws AnalyticsStudioNotFound
     */
    public function build(string $roundId, string $entryId): array
    {
        $context = $this->contextLoader->load($roundId);
        $entrant = null;

        foreach ($this->getAnalyticsStudioEntrants->forRound($context->round) as $candidate) {
            if ($candidate->key() === strtolower($entryId)) {
                $entrant = $candidate;
                break;
            }
        }

        if ($entrant === null) {
            throw new AnalyticsStudioNotFound();
        }

        $key = implode('.', [
            'entrant',
            $context->round->id,
            $entrant->key(),
            $context->visibleHash,
            $context->cutOffMs(),
            AnalyticsStudioAnalyticsBuilder::entrantsHash([$entrant]),
        ]);

        /** @var array<string, mixed> $payload */
        $payload = $this->analyticsStudioCache->get($key, function (ItemInterface $item) use ($context, $entrant): array {
            $item->expiresAfter(self::TTL_SECONDS);

            return $this->compute($context, $entrant);
        });

        // Predictions from their own cache (per player and visible puzzle set), merged into each analysable member
        $members = is_array($payload['members'] ?? null) ? $payload['members'] : [];
        foreach ($entrant->members as $index => $member) {
            $predictions = $this->predictions->memberJson($member, $context->visible);

            if (is_array($predictions) && is_array($members[$index] ?? null)) {
                $members[$index] = [...$predictions, ...$members[$index]];
            }
        }

        return [...$payload, 'members' => $members];
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(AnalyticsStudioContext $context, AnalyticsStudioEntrant $entrant): array
    {
        $round = $context->round;
        $stats = $this->puzzleStatsCache->of($context->visiblePuzzleIds(), $round->category);
        $collected = $this->analyticsBuilder->collect($context, [$entrant], $stats);
        $memberIds = $entrant->analysablePlayerIds();
        $cutOff = $round->historyCutOff();

        $recentSimilar = $this->getAnalyticsStudioSimilarHistory->recentSimilar($memberIds, $context->visible, $cutOff, $round->id, $context->now);
        $recentForm = $this->getAnalyticsStudioSimilarHistory->recentForm($memberIds, $context->visiblePiecesCounts(), $cutOff, $round->id, $context->now);

        $members = [];
        foreach ($entrant->members as $member) {
            $playerId = $member->analysablePlayerId();

            if ($playerId === null) {
                $members[] = new stdClass();
                continue;
            }

            $attempts = [];
            $similar = [];
            $form = [];

            foreach ($context->visible as $slotKey => $puzzle) {
                $attempts[$slotKey] = self::attemptsOn($collected['attempts'][$playerId] ?? [], strtolower($puzzle->puzzleId));
                $similar[$slotKey] = array_map(static fn (array $solve): array => [
                    'puzzleName' => $solve['puzzleName'],
                    'brand' => $solve['brand'],
                    'pieces' => $solve['pieces'],
                    'seconds' => $solve['seconds'],
                    'solvedAtMs' => RoundClock::milliseconds($solve['solvedAt']),
                    'solvedAtPrecision' => $solve['precision']->value,
                ], $recentSimilar[$playerId][$slotKey] ?? []);
            }

            // Newest first, per visible piece count
            foreach ($context->visiblePiecesCounts() as $pieces) {
                $seconds = array_map(static fn (array $time): int => $time['seconds'], $recentForm[$playerId][$pieces] ?? []);
                $sorted = $seconds;
                sort($sorted);
                $form[(string) $pieces] = [
                    'medianSeconds' => AnalyticsStudioDistribution::percentile($sorted, 0.5),
                    'seconds' => $seconds,
                ];
            }

            $members[] = [
                'attempts' => $attempts === [] ? new stdClass() : $attempts,
                'recentSimilar' => $similar === [] ? new stdClass() : $similar,
                'recentForm' => $form === [] ? new stdClass() : $form,
            ];
        }

        $payload = [
            'visibleHash' => $context->visibleHash,
            'cutOffMs' => $context->cutOffMs(),
            'key' => $entrant->key(),
            'analytics' => $collected['rows'][$entrant->key()],
            'members' => $members,
            'earlierRounds' => $this->earlierRounds($context, $entrant),
        ];

        if ($entrant->isTeam()) {
            $lineupKey = AnalyticsStudioAnalyticsBuilder::lineupKey($entrant);
            $lineupAttempts = [];

            if ($lineupKey !== null) {
                foreach ($context->visible as $slotKey => $puzzle) {
                    $lineupAttempts[$slotKey] = self::attemptsOn($collected['lineups'][$lineupKey] ?? [], strtolower($puzzle->puzzleId));
                }
            }

            $payload['lineupAttempts'] = $lineupKey === null ? null : ($lineupAttempts === [] ? new stdClass() : $lineupAttempts);
        }

        return $payload;
    }

    /**
     * @param list<AnalyticsStudioAttempt> $attempts newest first
     * @return list<array<string, mixed>>
     */
    private static function attemptsOn(array $attempts, string $puzzleId): array
    {
        $list = [];

        foreach ($attempts as $attempt) {
            if ($attempt->puzzleId === $puzzleId) {
                $list[] = AnalyticsStudioAttempts::toJson($attempt);
            }
        }

        return $list;
    }

    /**
     * The entry's people in the event's other published rounds (R19) - the official rows of OfficialEntrantsView (no
     * viewer), found by participant: a solo row of one of them, or a pair/team with one of them.
     *
     * @return list<array{roundName: string, slug: string, category: string, rank: int, seconds: null|int, piecesPlaced: null|int, piecesCount: null|int, qualified: bool, teamName: null|string}>
     */
    private function earlierRounds(AnalyticsStudioContext $context, AnalyticsStudioEntrant $entrant): array
    {
        /** @var array<string, RoundCategory> $published */
        $published = [];
        /** @var array<string, AnalyticsStudioEventRound> $roundsById */
        $roundsById = [];

        foreach ($context->round->eventRounds as $eventRound) {
            if ($eventRound->published && $eventRound->id !== $context->round->id) {
                $published[$eventRound->id] = $eventRound->category;
                $roundsById[$eventRound->id] = $eventRound;
            }
        }

        $participantIds = $entrant->participantIds();

        if ($published === [] || $participantIds === []) {
            return [];
        }

        $rows = [];
        $matched = [];

        foreach ($this->officialEntrantsView->forRounds($published, null) as $roundId => $official) {
            foreach ($official->entries as $entry) {
                if (array_intersect($entry->participantIds, $participantIds) !== []) {
                    $matched[] = [strtolower((string) $roundId), $entry];
                }
            }
        }

        // The pieces of an unfinished result, when the public may know them - those rounds' puzzles only then
        $unfinishedRounds = [];
        foreach ($matched as [$roundId, $entry]) {
            if ($entry->result->isUnfinished()) {
                $unfinishedRounds[$roundId] = true;
            }
        }
        $puzzles = $unfinishedRounds !== [] ? $this->roundPuzzlesVisibleNow->forRounds(array_keys($unfinishedRounds), $context->now) : [];

        foreach ($matched as [$roundId, $entry]) {
            $eventRound = $roundsById[$roundId];
            $piecesCount = null;

            if ($entry->result->isUnfinished() && isset($puzzles[$roundId])) {
                $chips = $eventRound->isPick ? PublishedEntryPuzzle::listOf($entry->puzzles, $puzzles[$roundId]->slots) : [];
                $piecesCount = UnfinishedPiecesCount::of($eventRound->isPick, $eventRound->puzzlesToSolve, $chips, $puzzles[$roundId]);
            }

            $rows[] = [
                'roundName' => $eventRound->name,
                'slug' => $eventRound->slug,
                'category' => $eventRound->category->value,
                'rank' => $entry->rank,
                'seconds' => $entry->result->seconds,
                'piecesPlaced' => $entry->result->piecesPlaced,
                'piecesCount' => $piecesCount,
                'qualified' => $entry->qualified,
                'teamName' => $entry->teamName,
            ];
        }

        return $rows;
    }
}
