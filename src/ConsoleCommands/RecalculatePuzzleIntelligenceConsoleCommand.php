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
            ->addOption('puzzle', null, InputOption::VALUE_REQUIRED, 'Recompute only for specific puzzle UUID')
            ->addOption('refit-prediction-model', null, InputOption::VALUE_NONE, 'Fit the prediction model now (it is refitted once a day by itself)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $specificPlayer = $input->getOption('player');
        $specificPuzzle = $input->getOption('puzzle');
        assert($specificPlayer === null || is_string($specificPlayer));
        assert($specificPuzzle === null || is_string($specificPuzzle));

        $result = $this->recalculator->recalculate($specificPlayer, $specificPuzzle, (bool) $input->getOption('refit-prediction-model'));

        $io->success(sprintf(
            "Puzzle insights recalculation complete:\n  %d direct baselines, %d interpolated (exponent: %.2f)\n  pace history: %d pairs changed, %d rows written\n  %d difficulties, prediction model %s, %d drift rows written\n  %d metrics, %d improvement ratios\n  %d skills, %d ratings\n  %d history, %d snapshots",
            $result['baselines_direct'],
            $result['baselines_interpolated'],
            $result['scaling_exponent'],
            $result['pace_history_changed_pairs'],
            $result['pace_history_rows_written'],
            $result['difficulties'],
            $result['prediction_model_fitted'] ? 'fitted' : 'kept',
            $result['drift_rows_written'],
            $result['metrics'],
            $result['improvement_ratios'],
            $result['skills'],
            $result['rating'],
            $result['history'],
            $result['snapshots'],
        ));

        if ($output->isVerbose()) {
            $io->table(['Step', 'Seconds', 'Peak MB'], array_map(
                static fn (string $step, float $seconds): array => [$step, $seconds, $result['peak_memory'][$step] ?? ''],
                array_keys($result['durations']),
                $result['durations'],
            ));
        }

        return self::SUCCESS;
    }
}
