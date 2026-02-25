<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('myspeedpuzzling:recalculate-puzzle-statistics')]
final class RecalculatePuzzleStatisticsConsoleCommand extends Command
{
    public function __construct(
        readonly private Connection $connection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->info('Recalculating puzzle statistics...');

        // Upsert all statistics using INSERT ... ON CONFLICT
        $affected = $this->connection->executeStatement("
            WITH player_best_per_type AS (
                SELECT puzzle_id, player_id, puzzling_type, MIN(seconds_to_solve) AS best_time
                FROM puzzle_solving_time
                WHERE seconds_to_solve IS NOT NULL
                GROUP BY puzzle_id, player_id, puzzling_type
            )
            INSERT INTO puzzle_statistics (
                puzzle_id,
                solved_times_count, fastest_time, average_time, slowest_time,
                solved_times_solo_count, fastest_time_solo, average_time_solo, slowest_time_solo,
                solved_times_duo_count, fastest_time_duo, average_time_duo, slowest_time_duo,
                solved_times_team_count, fastest_time_team, average_time_team, slowest_time_team,
                average_time_first_attempt, average_time_first_attempt_solo, average_time_first_attempt_duo, average_time_first_attempt_team,
                fastest_time_first_attempt, fastest_time_first_attempt_solo, fastest_time_first_attempt_duo, fastest_time_first_attempt_team
            )
            SELECT
                pst.puzzle_id,

                COUNT(*),
                MIN(pst.seconds_to_solve),
                (SELECT AVG(pb.best_time)::int FROM player_best_per_type pb WHERE pb.puzzle_id = pst.puzzle_id),
                MAX(pst.seconds_to_solve),

                COUNT(*) FILTER (WHERE pst.puzzling_type = 'solo'),
                MIN(pst.seconds_to_solve) FILTER (WHERE pst.puzzling_type = 'solo'),
                (SELECT AVG(pb.best_time)::int FROM player_best_per_type pb WHERE pb.puzzle_id = pst.puzzle_id AND pb.puzzling_type = 'solo'),
                MAX(pst.seconds_to_solve) FILTER (WHERE pst.puzzling_type = 'solo'),

                COUNT(*) FILTER (WHERE pst.puzzling_type = 'duo'),
                MIN(pst.seconds_to_solve) FILTER (WHERE pst.puzzling_type = 'duo'),
                (SELECT AVG(pb.best_time)::int FROM player_best_per_type pb WHERE pb.puzzle_id = pst.puzzle_id AND pb.puzzling_type = 'duo'),
                MAX(pst.seconds_to_solve) FILTER (WHERE pst.puzzling_type = 'duo'),

                COUNT(*) FILTER (WHERE pst.puzzling_type = 'team'),
                MIN(pst.seconds_to_solve) FILTER (WHERE pst.puzzling_type = 'team'),
                (SELECT AVG(pb.best_time)::int FROM player_best_per_type pb WHERE pb.puzzle_id = pst.puzzle_id AND pb.puzzling_type = 'team'),
                MAX(pst.seconds_to_solve) FILTER (WHERE pst.puzzling_type = 'team'),

                (AVG(pst.seconds_to_solve) FILTER (WHERE pst.first_attempt = true))::int,
                (AVG(pst.seconds_to_solve) FILTER (WHERE pst.first_attempt = true AND pst.puzzling_type = 'solo'))::int,
                (AVG(pst.seconds_to_solve) FILTER (WHERE pst.first_attempt = true AND pst.puzzling_type = 'duo'))::int,
                (AVG(pst.seconds_to_solve) FILTER (WHERE pst.first_attempt = true AND pst.puzzling_type = 'team'))::int,
                MIN(pst.seconds_to_solve) FILTER (WHERE pst.first_attempt = true),
                MIN(pst.seconds_to_solve) FILTER (WHERE pst.first_attempt = true AND pst.puzzling_type = 'solo'),
                MIN(pst.seconds_to_solve) FILTER (WHERE pst.first_attempt = true AND pst.puzzling_type = 'duo'),
                MIN(pst.seconds_to_solve) FILTER (WHERE pst.first_attempt = true AND pst.puzzling_type = 'team')
            FROM puzzle_solving_time pst
            GROUP BY pst.puzzle_id
            ON CONFLICT (puzzle_id) DO UPDATE SET
                solved_times_count = EXCLUDED.solved_times_count,
                fastest_time = EXCLUDED.fastest_time,
                average_time = EXCLUDED.average_time,
                slowest_time = EXCLUDED.slowest_time,

                solved_times_solo_count = EXCLUDED.solved_times_solo_count,
                fastest_time_solo = EXCLUDED.fastest_time_solo,
                average_time_solo = EXCLUDED.average_time_solo,
                slowest_time_solo = EXCLUDED.slowest_time_solo,

                solved_times_duo_count = EXCLUDED.solved_times_duo_count,
                fastest_time_duo = EXCLUDED.fastest_time_duo,
                average_time_duo = EXCLUDED.average_time_duo,
                slowest_time_duo = EXCLUDED.slowest_time_duo,

                solved_times_team_count = EXCLUDED.solved_times_team_count,
                fastest_time_team = EXCLUDED.fastest_time_team,
                average_time_team = EXCLUDED.average_time_team,
                slowest_time_team = EXCLUDED.slowest_time_team,

                average_time_first_attempt = EXCLUDED.average_time_first_attempt,
                average_time_first_attempt_solo = EXCLUDED.average_time_first_attempt_solo,
                average_time_first_attempt_duo = EXCLUDED.average_time_first_attempt_duo,
                average_time_first_attempt_team = EXCLUDED.average_time_first_attempt_team,
                fastest_time_first_attempt = EXCLUDED.fastest_time_first_attempt,
                fastest_time_first_attempt_solo = EXCLUDED.fastest_time_first_attempt_solo,
                fastest_time_first_attempt_duo = EXCLUDED.fastest_time_first_attempt_duo,
                fastest_time_first_attempt_team = EXCLUDED.fastest_time_first_attempt_team
        ");

        // Reset statistics for puzzles with no solving times
        $this->connection->executeStatement("
            UPDATE puzzle_statistics ps
            SET
                solved_times_count = 0,
                fastest_time = NULL,
                average_time = NULL,
                slowest_time = NULL,
                solved_times_solo_count = 0,
                fastest_time_solo = NULL,
                average_time_solo = NULL,
                slowest_time_solo = NULL,
                solved_times_duo_count = 0,
                fastest_time_duo = NULL,
                average_time_duo = NULL,
                slowest_time_duo = NULL,
                solved_times_team_count = 0,
                fastest_time_team = NULL,
                average_time_team = NULL,
                slowest_time_team = NULL,
                average_time_first_attempt = NULL,
                average_time_first_attempt_solo = NULL,
                average_time_first_attempt_duo = NULL,
                average_time_first_attempt_team = NULL,
                fastest_time_first_attempt = NULL,
                fastest_time_first_attempt_solo = NULL,
                fastest_time_first_attempt_duo = NULL,
                fastest_time_first_attempt_team = NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM puzzle_solving_time pst WHERE pst.puzzle_id = ps.puzzle_id
            )
            AND ps.solved_times_count > 0
        ");

        $io->success("Processed $affected puzzle statistics");

        return self::SUCCESS;
    }
}
