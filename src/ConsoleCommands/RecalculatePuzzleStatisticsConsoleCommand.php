<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\PuzzleStatisticsCalculator;
use SpeedPuzzling\Web\Value\PuzzleStatisticsData;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('myspeedpuzzling:recalculate-puzzle-statistics')]
final class RecalculatePuzzleStatisticsConsoleCommand extends Command
{
    public function __construct(
        readonly private Connection $connection,
        readonly private PuzzleStatisticsCalculator $calculator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $puzzleIds */
        $puzzleIds = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT puzzle_id FROM puzzle_solving_time',
        );

        $io->info(sprintf('Recalculating statistics for %d puzzles...', count($puzzleIds)));

        $progressBar = new ProgressBar($output, count($puzzleIds));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');
        $progressBar->start();

        $processed = 0;

        foreach ($puzzleIds as $puzzleId) {
            $data = $this->calculator->calculateForPuzzle(Uuid::fromString($puzzleId));
            $this->upsertStatistics($puzzleId, $data);
            $processed++;
            $progressBar->advance();
        }

        $progressBar->finish();
        $output->writeln('');

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

        $io->success("Recalculated statistics for $processed puzzles");

        return self::SUCCESS;
    }

    private function upsertStatistics(string $puzzleId, PuzzleStatisticsData $data): void
    {
        $this->connection->executeStatement("
            INSERT INTO puzzle_statistics (
                puzzle_id,
                solved_times_count, fastest_time, average_time, slowest_time,
                solved_times_solo_count, fastest_time_solo, average_time_solo, slowest_time_solo,
                solved_times_duo_count, fastest_time_duo, average_time_duo, slowest_time_duo,
                solved_times_team_count, fastest_time_team, average_time_team, slowest_time_team,
                average_time_first_attempt, average_time_first_attempt_solo, average_time_first_attempt_duo, average_time_first_attempt_team,
                fastest_time_first_attempt, fastest_time_first_attempt_solo, fastest_time_first_attempt_duo, fastest_time_first_attempt_team
            ) VALUES (
                :puzzleId,
                :totalCount, :fastestTime, :averageTime, :slowestTime,
                :soloCount, :fastestTimeSolo, :averageTimeSolo, :slowestTimeSolo,
                :duoCount, :fastestTimeDuo, :averageTimeDuo, :slowestTimeDuo,
                :teamCount, :fastestTimeTeam, :averageTimeTeam, :slowestTimeTeam,
                :averageTimeFirstAttempt, :averageTimeFirstAttemptSolo, :averageTimeFirstAttemptDuo, :averageTimeFirstAttemptTeam,
                :fastestTimeFirstAttempt, :fastestTimeFirstAttemptSolo, :fastestTimeFirstAttemptDuo, :fastestTimeFirstAttemptTeam
            )
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
        ", [
            'puzzleId' => $puzzleId,
            'totalCount' => $data->totalCount,
            'fastestTime' => $data->fastestTime,
            'averageTime' => $data->averageTime,
            'slowestTime' => $data->slowestTime,
            'soloCount' => $data->soloCount,
            'fastestTimeSolo' => $data->fastestTimeSolo,
            'averageTimeSolo' => $data->averageTimeSolo,
            'slowestTimeSolo' => $data->slowestTimeSolo,
            'duoCount' => $data->duoCount,
            'fastestTimeDuo' => $data->fastestTimeDuo,
            'averageTimeDuo' => $data->averageTimeDuo,
            'slowestTimeDuo' => $data->slowestTimeDuo,
            'teamCount' => $data->teamCount,
            'fastestTimeTeam' => $data->fastestTimeTeam,
            'averageTimeTeam' => $data->averageTimeTeam,
            'slowestTimeTeam' => $data->slowestTimeTeam,
            'averageTimeFirstAttempt' => $data->averageTimeFirstAttempt,
            'averageTimeFirstAttemptSolo' => $data->averageTimeFirstAttemptSolo,
            'averageTimeFirstAttemptDuo' => $data->averageTimeFirstAttemptDuo,
            'averageTimeFirstAttemptTeam' => $data->averageTimeFirstAttemptTeam,
            'fastestTimeFirstAttempt' => $data->fastestTimeFirstAttempt,
            'fastestTimeFirstAttemptSolo' => $data->fastestTimeFirstAttemptSolo,
            'fastestTimeFirstAttemptDuo' => $data->fastestTimeFirstAttemptDuo,
            'fastestTimeFirstAttemptTeam' => $data->fastestTimeFirstAttemptTeam,
        ]);
    }
}
