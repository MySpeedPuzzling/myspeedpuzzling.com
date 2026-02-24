<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\StopwatchMilestone;

readonly final class GetStopwatchMilestones
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<StopwatchMilestone>
     */
    public function forPuzzleAndPlayer(string $puzzleId, string $playerId): array
    {
        $milestones = [];

        // Get puzzle average time
        $avgQuery = <<<SQL
SELECT average_time_solo
FROM puzzle_statistics
WHERE puzzle_id = :puzzleId
SQL;

        /** @var false|array{average_time_solo: null|string} $avgStats */
        $avgStats = $this->database
            ->executeQuery($avgQuery, ['puzzleId' => $puzzleId])
            ->fetchAssociative();

        if (is_array($avgStats) && $avgStats['average_time_solo'] !== null && (int) $avgStats['average_time_solo'] > 0) {
            $milestones[] = new StopwatchMilestone(
                label: 'Average',
                timeSeconds: (int) $avgStats['average_time_solo'],
                type: 'average',
                avatar: null,
            );
        }

        // Get fastest solo time with player info
        $fastestQuery = <<<SQL
SELECT
    pst.seconds_to_solve,
    p.name AS player_name,
    p.code AS player_code,
    p.avatar AS player_avatar
FROM puzzle_solving_time pst
JOIN player p ON p.id = pst.player_id
WHERE pst.puzzle_id = :puzzleId
    AND pst.seconds_to_solve IS NOT NULL
    AND pst.puzzlers_count = 1
ORDER BY pst.seconds_to_solve ASC
LIMIT 1
SQL;

        /** @var false|array{seconds_to_solve: int, player_name: null|string, player_code: string, player_avatar: null|string} $fastest */
        $fastest = $this->database
            ->executeQuery($fastestQuery, ['puzzleId' => $puzzleId])
            ->fetchAssociative();

        if (is_array($fastest)) {
            $fastestLabel = $fastest['player_name'] !== null && $fastest['player_name'] !== '' ? $fastest['player_name'] : $fastest['player_code'];

            $milestones[] = new StopwatchMilestone(
                label: $fastestLabel . ' (fastest)',
                timeSeconds: (int) $fastest['seconds_to_solve'],
                type: 'fastest',
                avatar: $fastest['player_avatar'],
            );
        }

        // Get current player's best solo time
        $myQuery = <<<SQL
SELECT
    p.name AS player_name,
    p.code AS player_code,
    p.avatar AS player_avatar,
    pst.seconds_to_solve
FROM puzzle_solving_time pst
JOIN player p ON p.id = pst.player_id
WHERE pst.puzzle_id = :puzzleId
    AND pst.player_id = :playerId
    AND pst.seconds_to_solve IS NOT NULL
    AND pst.puzzlers_count = 1
ORDER BY pst.seconds_to_solve ASC
LIMIT 1
SQL;

        /** @var false|array{player_name: null|string, player_code: string, player_avatar: null|string, seconds_to_solve: int} $myBest */
        $myBest = $this->database
            ->executeQuery($myQuery, [
                'puzzleId' => $puzzleId,
                'playerId' => $playerId,
            ])
            ->fetchAssociative();

        if (is_array($myBest)) {
            $myLabel = $myBest['player_name'] !== null && $myBest['player_name'] !== '' ? $myBest['player_name'] : $myBest['player_code'];

            $milestones[] = new StopwatchMilestone(
                label: $myLabel . ' (you)',
                timeSeconds: (int) $myBest['seconds_to_solve'],
                type: 'self',
                avatar: $myBest['player_avatar'],
            );
        }

        // Get favorite players' best solo times for this puzzle
        $favQuery = <<<SQL
SELECT
    fav.name AS player_name,
    fav.code AS player_code,
    fav.avatar AS player_avatar,
    MIN(pst.seconds_to_solve) AS seconds_to_solve
FROM player
CROSS JOIN LATERAL json_array_elements_text(player.favorite_players::json) AS fav_player_id
JOIN player fav ON fav.id = fav_player_id::uuid
JOIN puzzle_solving_time pst ON pst.player_id = fav.id AND pst.puzzle_id = :puzzleId
WHERE player.id = :playerId
    AND pst.seconds_to_solve IS NOT NULL
    AND pst.puzzlers_count = 1
GROUP BY fav.id, fav.name, fav.code, fav.avatar
ORDER BY seconds_to_solve ASC
LIMIT 5
SQL;

        $favData = $this->database
            ->executeQuery($favQuery, [
                'puzzleId' => $puzzleId,
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        foreach ($favData as $row) {
            /** @var array{player_name: null|string, player_code: string, player_avatar: null|string, seconds_to_solve: int} $row */
            $label = $row['player_name'] !== null && $row['player_name'] !== '' ? $row['player_name'] : $row['player_code'];

            $milestones[] = new StopwatchMilestone(
                label: $label,
                timeSeconds: (int) $row['seconds_to_solve'],
                type: 'favorite',
                avatar: $row['player_avatar'],
            );
        }

        usort($milestones, static fn (StopwatchMilestone $a, StopwatchMilestone $b): int => $a->timeSeconds <=> $b->timeSeconds);

        $milestones = $this->fillGapsWithRandomPlayers($puzzleId, $playerId, $milestones);

        // Assign ranks based on sorted solo times
        $soloTimes = $this->allSoloTimesForPuzzle($puzzleId);

        return array_map(static function (StopwatchMilestone $m) use ($soloTimes): StopwatchMilestone {
            if ($m->type === 'average') {
                return $m;
            }

            // Find rank: count how many solo times are <= this milestone's time
            $rank = 0;
            foreach ($soloTimes as $time) {
                if ($time <= $m->timeSeconds) {
                    $rank++;
                } else {
                    break;
                }
            }

            return new StopwatchMilestone(
                label: $m->label,
                timeSeconds: $m->timeSeconds,
                type: $m->type,
                avatar: $m->avatar,
                rank: $rank > 0 ? $rank : 1,
            );
        }, $milestones);
    }

    /**
     * @param array<StopwatchMilestone> $milestones Already sorted by timeSeconds
     * @return array<StopwatchMilestone>
     */
    private function fillGapsWithRandomPlayers(string $puzzleId, string $playerId, array $milestones): array
    {
        if ($milestones === []) {
            return $milestones;
        }

        // Fetch best solo time per player for this puzzle (excluding current player)
        $query = <<<SQL
SELECT
    p.id AS player_id,
    p.name AS player_name,
    p.code AS player_code,
    p.avatar AS player_avatar,
    MIN(pst.seconds_to_solve) AS seconds_to_solve
FROM puzzle_solving_time pst
JOIN player p ON p.id = pst.player_id
WHERE pst.puzzle_id = :puzzleId
    AND pst.player_id != :playerId
    AND pst.seconds_to_solve IS NOT NULL
    AND pst.puzzlers_count = 1
GROUP BY p.id, p.name, p.code, p.avatar
ORDER BY seconds_to_solve ASC
SQL;

        /** @var array<array{player_id: string, player_name: null|string, player_code: string, player_avatar: null|string, seconds_to_solve: int|string}> $allSolvers */
        $allSolvers = $this->database
            ->executeQuery($query, [
                'puzzleId' => $puzzleId,
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        if ($allSolvers === []) {
            return $milestones;
        }

        // Collect times already used as milestones to avoid duplicates
        $usedTimes = [];
        foreach ($milestones as $m) {
            $usedTimes[$m->timeSeconds] = true;
        }

        $gapThreshold = 120; // 2 minutes

        // Walk through milestones and fill gaps
        $filled = [];
        $previousTime = 0;

        foreach ($milestones as $milestone) {
            // Fill gap between previous milestone and current one
            $this->addRandomSolversInRange($previousTime, $milestone->timeSeconds, $gapThreshold, $allSolvers, $usedTimes, $filled);
            $filled[] = $milestone;
            $previousTime = $milestone->timeSeconds;
        }

        // Also fill after the last milestone (up to last milestone + some buffer)
        $lastTime = $milestones[array_key_last($milestones)]->timeSeconds;
        $maxSolverTime = (int) $allSolvers[array_key_last($allSolvers)]['seconds_to_solve'];
        if ($maxSolverTime > $lastTime) {
            $this->addRandomSolversInRange($lastTime, $maxSolverTime + 1, $gapThreshold, $allSolvers, $usedTimes, $filled);
        }

        return $filled;
    }

    /**
     * @param array<array{player_id: string, player_name: null|string, player_code: string, player_avatar: null|string, seconds_to_solve: int|string}> $allSolvers
     * @param array<int, true> $usedTimes
     * @param array<StopwatchMilestone> $filled
     */
    private function addRandomSolversInRange(int $fromTime, int $toTime, int $gapThreshold, array $allSolvers, array &$usedTimes, array &$filled): void
    {
        $gap = $toTime - $fromTime;
        if ($gap <= $gapThreshold) {
            return;
        }

        // Find solvers in this range
        $candidates = [];
        foreach ($allSolvers as $solver) {
            $time = (int) $solver['seconds_to_solve'];
            if ($time > $fromTime && $time < $toTime && !isset($usedTimes[$time])) {
                $candidates[] = $solver;
            }
        }

        if ($candidates === []) {
            return;
        }

        // Pick solvers at ~2 minute intervals
        $currentTarget = $fromTime + $gapThreshold;
        while ($currentTarget < $toTime) {
            // Find closest candidate to current target
            $bestCandidate = null;
            $bestDistance = PHP_INT_MAX;
            $bestIndex = 0;

            foreach ($candidates as $index => $candidate) {
                $time = (int) $candidate['seconds_to_solve'];
                $distance = abs($time - $currentTarget);
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestCandidate = $candidate;
                    $bestIndex = $index;
                }
            }

            if ($bestCandidate === null) {
                break;
            }

            $time = (int) $bestCandidate['seconds_to_solve'];
            $label = $bestCandidate['player_name'] !== null && $bestCandidate['player_name'] !== '' ? $bestCandidate['player_name'] : $bestCandidate['player_code'];

            $filled[] = new StopwatchMilestone(
                label: $label,
                timeSeconds: $time,
                type: 'other',
                avatar: $bestCandidate['player_avatar'],
            );

            $usedTimes[$time] = true;
            unset($candidates[$bestIndex]);
            $candidates = array_values($candidates);

            $currentTarget = $time + $gapThreshold;
        }
    }

    /**
     * @return array<int>
     */
    public function allSoloTimesForPuzzle(string $puzzleId): array
    {
        $query = <<<SQL
SELECT MIN(seconds_to_solve) AS seconds_to_solve
FROM puzzle_solving_time
WHERE puzzle_id = :puzzleId
    AND seconds_to_solve IS NOT NULL
    AND puzzlers_count = 1
GROUP BY player_id
ORDER BY seconds_to_solve ASC
SQL;

        /** @var array<int|string> $data */
        $data = $this->database
            ->executeQuery($query, ['puzzleId' => $puzzleId])
            ->fetchFirstColumn();

        return array_map(static fn (int|string $value): int => (int) $value, $data);
    }
}
