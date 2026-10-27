<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Group;

use DateTimeImmutable;
use SpeedPuzzling\Web\Query\GetGroupPredictionResults;
use SpeedPuzzling\Web\Query\GetGroupPuzzleFirstAttempts;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PaceHistoryReadiness;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PaceTimeline;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PaceTimelineLoader;

/**
 * Loads every pair/team result with its as-of inputs (GroupResultSet): the members' paces and drift before the solve
 * (the pace history with its rows' drift - PaceTimeline::paceBefore(), directRowBefore()) and the puzzle's shown
 * difficulty before it (GroupDifficultyTimeline). Three statements, two through a cursor; the pace history and the
 * puzzles' index events are released before it returns. Nothing before the pace history is built (an empty set).
 */
readonly final class GroupResultSetLoader
{
    public function __construct(
        private GetGroupPredictionResults $getGroupPredictionResults,
        private GetGroupPuzzleFirstAttempts $getGroupPuzzleFirstAttempts,
        private PaceTimelineLoader $paceTimelineLoader,
        private PaceHistoryReadiness $paceHistoryReadiness,
    ) {
    }

    public function load(DateTimeImmutable $now): GroupResultSet
    {
        $set = new GroupResultSet();
        $scalingExponent = $this->paceHistoryReadiness->scalingExponent();

        if ($scalingExponent === null) {
            return $set;
        }

        $timeline = $this->paceTimelineLoader->allWithRowStates($scalingExponent);
        $difficulty = GroupDifficultyTimeline::build($this->getGroupPuzzleFirstAttempts->rows(), $timeline);
        $teams = [];
        $puzzles = [];
        $players = [];

        foreach ($this->getGroupPredictionResults->rows($now) as $row) {
            /** @var list<null|string> $members */
            $members = json_decode($row['members'], true, flags: JSON_THROW_ON_ERROR);
            $pieces = (int) $row['pieces_count'];
            $solved = (float) $row['solved_ts'];
            $tracked = (float) $row['tracked_ts'];
            $first = self::bool($row['first_attempt']);
            $unboxed = self::bool($row['unboxed']);
            $secret = self::bool($row['secret']);

            $set->memberStart[] = count($set->memberPlayers);
            [$rate, $rateWithoutDrift, $known] = self::rates($timeline, $members, $pieces, $solved, $tracked);

            foreach ($members as $playerId) {
                if ($playerId !== null) {
                    $set->memberPlayers[] = $players[$playerId] ??= count($players);
                }
            }

            [$k, $sumLog, $score] = $difficulty->at($row['puzzle_id'], $solved, $tracked);

            $set->id[] = $row['id'];
            $set->team[] = $teams[$row['team_id']] ??= count($teams);
            $set->puzzle[] = $puzzles[$row['puzzle_id']] ??= count($puzzles);
            $set->pieces[] = $pieces;
            $set->seconds[] = (int) $row['seconds_to_solve'];
            $set->solved[] = $solved;
            $set->tracked[] = $tracked;
            $set->first[] = $first;
            $set->competition[] = self::bool($row['competition']);
            $set->members[] = count($members);
            $set->unknown[] = count($members) - $known;
            $set->rate[] = $rate;
            $set->rateWithoutDrift[] = $rateWithoutDrift;
            $set->k[] = $k;
            $set->sumLog[] = $sumLog;
            $set->score[] = $score;
            $set->imageLog[] = $secret || $row['image_log'] === null ? null : (float) $row['image_log'];
            $set->notPredictable[] = match (true) {
                $unboxed => GroupResultSet::NOT_PREDICTABLE_UNBOXED,
                $secret => GroupResultSet::NOT_PREDICTABLE_SECRET,
                $known === 0 || count($members) < 2 => GroupResultSet::NOT_PREDICTABLE_NO_PACE,
                default => 0,
            };
            $set->fingerprint[] = GroupPredictionModel::fingerprint($row['team_id'], $row['puzzle_id'], $pieces, $solved, $first, $unboxed, $secret);
        }

        return $set;
    }

    /**
     * Σ 1 / (pace × e^drift) and Σ 1 / pace of the members with a pace at the piece count before the solve, and how
     * many they are. The drift is the member's own row's at that piece count (an interpolated pace has none - like the
     * shown model's first try).
     *
     * @param list<null|string> $members null = a guest
     * @return array{0: float, 1: float, 2: int}
     */
    private static function rates(PaceTimeline $timeline, array $members, int $pieces, float $solved, float $tracked): array
    {
        $rate = 0.0;
        $rateWithoutDrift = 0.0;
        $known = 0;

        foreach ($members as $playerId) {
            if ($playerId === null) {
                continue;
            }

            $pace = $timeline->paceBefore($playerId, $pieces, $solved, $tracked);

            if ($pace === null || $pace <= 0) {
                continue;
            }

            $drift = $timeline->directRowBefore($playerId, $pieces, $solved, $tracked)['drift'] ?? null;
            $rate += 1.0 / ($pace * exp($drift ?? 0.0));
            $rateWithoutDrift += 1.0 / $pace;
            $known++;
        }

        return [$rate, $rateWithoutDrift, $known];
    }

    private static function bool(bool|int|string $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}
