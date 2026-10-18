<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PlayerRatingPortfolio;

/**
 * Stores what the MSP rating step computes in memory, for Insights (docs/features/insights/computation.md):
 * every entry behind every rating (player_rating_entry) and the sorted times of every rated and nearly rated puzzle
 * (puzzle_rating_times). Only changed rows are written (ChangedRowsSync, RatingEntriesSync).
 */
readonly final class RatingInsightsWriter
{
    public function __construct(
        private Connection $connection,
        private ChangedRowsSync $sync,
    ) {
    }

    public function openEntries(\DateTimeImmutable $now): RatingEntriesSync
    {
        $run = $this->sync->open(
            'player_rating_entry',
            ['player_id' => 'uuid', 'pieces_count' => 'integer', 'puzzle_id' => 'uuid', 'portfolio' => 'varchar'],
            [
                'seconds' => 'integer',
                'solved_at' => 'timestamp',
                'percentile' => 'double precision',
                'difficulty_weight' => 'double precision',
                'decay' => 'double precision',
                'points' => 'double precision',
                'in_top' => 'boolean',
            ],
        );

        return new RatingEntriesSync($this->connection, $run, $now);
    }

    public function addEntries(RatingEntriesSync $entries, string $playerId, int $piecesCount, PlayerRatingPortfolio $portfolio): void
    {
        $entries->add($playerId, $piecesCount, $portfolio);
    }

    /**
     * @param null|string $onlyPlayer a run for one player (--player) speaks only for that player's rows
     * @return array{written: int, deleted: int}
     */
    public function finishEntries(RatingEntriesSync $entries, null|string $onlyPlayer): array
    {
        return $entries->finish($onlyPlayer);
    }

    public function openTimes(): ChangedRowsSyncRun
    {
        return $this->sync->open(
            'puzzle_rating_times',
            ['puzzle_id' => 'uuid'],
            [
                'pieces_count' => 'integer',
                'solver_count' => 'integer',
                'qualifies' => 'boolean',
                'difficulty_weight' => 'double precision',
                'first_attempt_times' => 'jsonb',
                'best_times' => 'jsonb',
            ],
        );
    }

    /**
     * Secret puzzles (hide_until / hide_image_until in the future) never get a row: they count in ratings like any
     * puzzle, but nothing derived from their times is kept for display.
     *
     * @param array<string, array{fa_times: list<int>, bt_times: list<int>, difficulty: float|null, sample_size: int, qualifies: bool}> $puzzleRankings
     */
    public function addTimes(ChangedRowsSyncRun $times, int $piecesCount, array $puzzleRankings, \DateTimeImmutable $now): void
    {
        if ($puzzleRankings === []) {
            return;
        }

        /** @var list<string> $secret */
        $secret = $this->connection->fetchFirstColumn('
            SELECT id FROM puzzle
            WHERE id IN (:puzzleIds)
                AND (hide_until > :now OR hide_image_until > :now)
        ', [
            'puzzleIds' => array_map(strval(...), array_keys($puzzleRankings)),
            'now' => $now->format('Y-m-d H:i:s'),
        ], ['puzzleIds' => ArrayParameterType::STRING]);
        $secret = array_flip($secret);

        foreach ($puzzleRankings as $puzzleId => $puzzle) {
            $puzzleId = (string) $puzzleId;

            if (isset($secret[$puzzleId])) {
                continue;
            }

            $times->add([
                'puzzle_id' => $puzzleId,
                'pieces_count' => $piecesCount,
                'solver_count' => count($puzzle['fa_times']),
                'qualifies' => $puzzle['qualifies'],
                'difficulty_weight' => $puzzle['difficulty'] !== null
                    ? MspRatingFormula::difficultyWeight($puzzle['difficulty'], $puzzle['sample_size'])
                    : null,
                'first_attempt_times' => $puzzle['fa_times'],
                'best_times' => $puzzle['bt_times'],
            ]);
        }
    }

    /**
     * @return array{written: int, deleted: int}
     */
    public function finishTimes(ChangedRowsSyncRun $times, \DateTimeImmutable $now): array
    {
        return $times->finish($now);
    }
}
