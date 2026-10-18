<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use DateTimeImmutable;
use SpeedPuzzling\Web\Query\GetGroupCoordinationConstants;
use SpeedPuzzling\Web\Query\GetInsightsGroupResults;
use SpeedPuzzling\Web\Results\Insights\GroupResultMember;
use SpeedPuzzling\Web\Results\Insights\GroupResultScore;
use SpeedPuzzling\Web\Results\Insights\GroupSynergy;
use SpeedPuzzling\Web\Results\Insights\InsightsGroupResult;
use SpeedPuzzling\Web\Results\Insights\PairsTeamsSynergyResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PaceHistoryReadiness;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PaceTimeline;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PaceTimelineLoader;
use SpeedPuzzling\Web\Value\GroupCoordination;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\ShrunkEffect;
use SpeedPuzzling\Web\Value\SynergyVerdict;

/**
 * "Who do I solve best with, and how?" (docs/features/insights/computation.md §4c.1). Every pair/team result is
 * compared with what its members' solo paces predict - expected = c(size, first try / repeat) × difficulty ÷
 * Σ 1/paceᵢ, every pace as of the solve (GroupExpectation) - and each pair/team (the exact set of people) gets the
 * shrunk effect of actual ÷ expected with its range. Pairs and teams are separate lists, never mixed with solo.
 *
 * A result has a score only when nobody in it is a guest, every member has a pace at the solve, the puzzle is rated
 * (more than insufficient confidence), boxed and not secret; a pair/team with a guest is listed without a score. A
 * pair/team with a member the viewer blocks is left out. Four statements (results, pace marker, the members' pace
 * history, constants), whatever the history size.
 */
readonly final class PairsTeamsSynergy
{
    public const int BEST_RESULTS = 5;

    public function __construct(
        private GetInsightsGroupResults $getInsightsGroupResults,
        private PaceHistoryReadiness $paceHistoryReadiness,
        private PaceTimelineLoader $paceTimelineLoader,
        private GetGroupCoordinationConstants $getGroupCoordinationConstants,
    ) {
    }

    /**
     * @param null|DateTimeImmutable $from results solved at or after (inclusive)
     * @param null|DateTimeImmutable $to results solved before (exclusive)
     */
    public function build(
        string $playerId,
        null|DateTimeImmutable $from = null,
        null|DateTimeImmutable $to = null,
        null|PiecesRange $pieces = null,
    ): PairsTeamsSynergyResult {
        $all = $this->getInsightsGroupResults->forPlayer($playerId);
        $results = [];

        foreach ($all->results as $result) {
            if (
                ($from !== null && $result->solvedAt < $from)
                || ($to !== null && $result->solvedAt >= $to)
                || ($pieces !== null && $pieces->contains($result->piecesCount) === false)
                || self::hasBlockedMember($result)
            ) {
                continue;
            }

            $results[] = $result;
        }

        if ($results === []) {
            return new PairsTeamsSynergyResult(0, 0, [], [], [], []);
        }

        $scalingExponent = $this->paceHistoryReadiness->scalingExponent();
        $timeline = $scalingExponent !== null
            ? $this->paceTimelineLoader->forPlayers($all->memberPlayerIds, $scalingExponent)
            : null;
        $constants = $this->getGroupCoordinationConstants->all();

        /** @var array<string, list<InsightsGroupResult>> $byTeam */
        $byTeam = [];
        /** @var array<string, list<GroupResultScore>> $scoresByTeam */
        $scoresByTeam = [];
        /** @var array<string, non-empty-list<float>> $aloneByTeam */
        $aloneByTeam = [];

        foreach ($results as $result) {
            $byTeam[$result->teamId][] = $result;

            if ($timeline === null) {
                continue;
            }

            $score = self::score($result, $timeline, $constants);

            if ($score !== null) {
                $scoresByTeam[$result->teamId][] = $score;
            }

            $alone = self::expectedAlone($result, $playerId, $timeline);

            if ($alone !== null) {
                $aloneByTeam[$result->teamId][] = $alone / $result->seconds;
            }
        }

        $pairs = [];
        $teams = [];
        $pairScores = [];
        $teamScores = [];
        $pairResults = 0;
        $teamResults = 0;

        foreach ($byTeam as $teamId => $resultsOfTeam) {
            $first = $resultsOfTeam[0];
            $scores = $scoresByTeam[$teamId] ?? [];
            $effect = ShrunkEffect::of(array_map(static fn (GroupResultScore $score): float => $score->ratio, $scores));

            $synergy = new GroupSynergy(
                teamId: $teamId,
                teamName: $first->teamName,
                size: $first->size,
                partners: array_values(array_filter(
                    $first->members,
                    static fn (GroupResultMember $member): bool => $member->playerId === null || strtolower($member->playerId) !== strtolower($playerId),
                )),
                resultsCount: count($resultsOfTeam),
                scoredCount: count($scores),
                effect: $effect,
                verdict: self::verdict($first, $effect),
                speedVsAlone: isset($aloneByTeam[$teamId]) ? self::median($aloneByTeam[$teamId]) : null,
            );

            if ($first->size === 2) {
                $pairs[] = $synergy;
                $pairScores = [...$pairScores, ...$scores];
                $pairResults += count($resultsOfTeam);
            } else {
                $teams[] = $synergy;
                $teamScores = [...$teamScores, ...$scores];
                $teamResults += count($resultsOfTeam);
            }
        }

        usort($pairs, self::bestFirst(...));
        usort($teams, self::bestFirst(...));

        return new PairsTeamsSynergyResult(
            pairResultsCount: $pairResults,
            teamResultsCount: $teamResults,
            pairs: $pairs,
            teams: $teams,
            bestPairResults: self::best($pairScores),
            bestTeamResults: self::best($teamScores),
        );
    }

    /**
     * @param array<int, array{first: float, repeat: float}> $constants
     */
    private static function score(InsightsGroupResult $result, PaceTimeline $timeline, array $constants): null|GroupResultScore
    {
        if ($result->puzzle === null || $result->difficulty === null || $result->unboxed) {
            return null;
        }

        $rate = GroupExpectation::combinedRate(
            $timeline,
            array_map(static fn (GroupResultMember $member): null|string => $member->playerId, $result->members),
            $result->piecesCount,
            $result->solvedEpoch,
            $result->trackedEpoch,
        );

        if ($rate === null) {
            return null;
        }

        $coordination = $constants[GroupCoordination::sizeGroup($result->size)][$result->firstAttempt ? 'first' : 'repeat'];
        $expected = GroupExpectation::expectedSeconds($coordination, $result->difficulty, $rate);

        if ($expected <= 0.0) {
            return null;
        }

        return new GroupResultScore($result, (int) round($expected), $result->seconds / $expected);
    }

    /**
     * Your own expected solo time on the puzzle at that moment: your pace × the difficulty.
     */
    private static function expectedAlone(InsightsGroupResult $result, string $playerId, PaceTimeline $timeline): null|float
    {
        if ($result->difficulty === null || $result->unboxed) {
            return null;
        }

        $pace = $timeline->paceAt(strtolower($playerId), $result->piecesCount, $result->solvedEpoch, $result->trackedEpoch);

        return $pace !== null && $pace > 0 ? $pace * $result->difficulty : null;
    }

    private static function verdict(InsightsGroupResult $result, null|ShrunkEffect $effect): SynergyVerdict
    {
        foreach ($result->members as $member) {
            if ($member->isGuest()) {
                return SynergyVerdict::NoSoloPace;
            }
        }

        if ($effect === null) {
            return SynergyVerdict::NotSureYet;
        }

        return match (true) {
            $effect->isFaster() => SynergyVerdict::GreatMatch,
            $effect->isSlower() => SynergyVerdict::FasterApart,
            default => SynergyVerdict::AsExpected,
        };
    }

    private static function hasBlockedMember(InsightsGroupResult $result): bool
    {
        foreach ($result->members as $member) {
            if ($member->blocked) {
                return true;
            }
        }

        return false;
    }

    private static function bestFirst(GroupSynergy $a, GroupSynergy $b): int
    {
        if ($a->effect !== null && $b->effect !== null) {
            return $a->effect->effect <=> $b->effect->effect ?: $b->resultsCount <=> $a->resultsCount;
        }

        if ($a->effect !== null || $b->effect !== null) {
            return $a->effect !== null ? -1 : 1;
        }

        return $b->resultsCount <=> $a->resultsCount ?: strcmp($a->teamId, $b->teamId);
    }

    /**
     * @param list<GroupResultScore> $scores
     *
     * @return list<GroupResultScore>
     */
    private static function best(array $scores): array
    {
        usort($scores, static fn (GroupResultScore $a, GroupResultScore $b): int => $a->ratio <=> $b->ratio ?: strcmp($a->result->timeId, $b->result->timeId));

        return array_slice($scores, 0, self::BEST_RESULTS);
    }

    /**
     * @param non-empty-list<float> $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2.0;
    }
}
