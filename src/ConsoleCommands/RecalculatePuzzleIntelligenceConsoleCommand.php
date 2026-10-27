<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('myspeedpuzzling:recalculate-puzzle-intelligence')]
final class RecalculatePuzzleIntelligenceConsoleCommand extends Command
{
    public function __construct(
        readonly private PuzzleIntelligenceRecalculator $recalculator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('player', null, InputOption::VALUE_REQUIRED, 'Recompute only for specific player UUID')
            ->addOption('puzzle', null, InputOption::VALUE_REQUIRED, 'Recompute only for specific puzzle UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $specificPlayer = $input->getOption('player');
        $specificPuzzle = $input->getOption('puzzle');
        assert($specificPlayer === null || is_string($specificPlayer));
        assert($specificPuzzle === null || is_string($specificPuzzle));

        $result = $this->recalculator->recalculate($specificPlayer, $specificPuzzle);

        $io->success(sprintf(
            "Puzzle insights recalculation complete:\n  %d direct baselines, %d interpolated (exponent: %.2f)\n  pace history: %d pairs changed, %d rows written\n  %d difficulties, %d drift rows written\n  %d metrics, %d improvement ratios\n  %d skills, %d ratings\n  %d history, %d snapshots\n  rating entries: %d written, %d deleted; puzzle rating times: %d written, %d deleted\n  insights generation %d",
            $result['baselines_direct'],
            $result['baselines_interpolated'],
            $result['scaling_exponent'],
            $result['pace_history_changed_pairs'],
            $result['pace_history_rows_written'],
            $result['difficulties'],
            $result['drift_rows_written'],
            $result['metrics'],
            $result['improvement_ratios'],
            $result['skills'],
            $result['rating'],
            $result['history'],
            $result['snapshots'],
            $result['rating_entries']['written'],
            $result['rating_entries']['deleted'],
            $result['rating_times']['written'],
            $result['rating_times']['deleted'],
            $result['generation'],
        ));

        if ($output->isVerbose()) {
            $io->table(['Step', 'Seconds', 'PHP peak MB', 'Resident peak MB (step)'], array_map(
                static fn (string $step, float $seconds): array => [$step, $seconds, $result['peak_memory'][$step] ?? '', $result['resident_peak'][$step] ?? '-'],
                array_keys($result['durations']),
                $result['durations'],
            ));
        }

        return self::SUCCESS;
    }
}
