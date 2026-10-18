<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Query\GetPredictionCalibration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The calibration monitor of the prediction windows (docs/features/puzzle-intelligence/README.md, "Step 8"): how
 * often the live predictions of the last days hit their 50 % and 80 % windows, per calibration bucket and overall. A
 * bucket with enough samples more than DRIFT_POINTS away from its target is a warning (a Sentry issue: the nightly fit
 * no longer matches what players do). Read only; daily cron.
 */
#[AsCommand(
    'myspeedpuzzling:report-prediction-calibration',
    'Reports how often the stored live predictions hit their 50 % / 80 % windows per bucket; warns when a bucket drifts from its target.',
)]
final class ReportPredictionCalibrationConsoleCommand extends Command
{
    public const float DRIFT_POINTS = 3.0;

    public function __construct(
        readonly private GetPredictionCalibration $getPredictionCalibration,
        readonly private ClockInterface $clock,
        readonly private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Predictions stored in the last N days', '90');
        $this->addOption('min-samples', null, InputOption::VALUE_REQUIRED, 'A bucket is judged from this many predictions on', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = max(1, (int) (is_string($input->getOption('days')) ? $input->getOption('days') : 90));
        $minimum = max(1, (int) (is_string($input->getOption('min-samples')) ? $input->getOption('min-samples') : 200));

        $rows = $this->getPredictionCalibration->since($this->clock->now()->modify("-{$days} days"));
        $table = [];
        $totals = [];

        foreach ($rows as $row) {
            $kind = str_starts_with($row['bucket'], 'r|') ? 'repeats' : 'first tries';
            $totals[$kind] ??= ['bucket' => 'all ' . $kind, 'n' => 0, 'hit50' => 0, 'hit80' => 0];
            $totals[$kind]['n'] += $row['n'];
            $totals[$kind]['hit50'] += $row['hit50'];
            $totals[$kind]['hit80'] += $row['hit80'];
        }

        foreach ([...array_values($totals), ...$rows] as $row) {
            $hit50 = 100 * $row['hit50'] / $row['n'];
            $hit80 = 100 * $row['hit80'] / $row['n'];
            $judged = $row['n'] >= $minimum;
            $drifted = $judged && (abs($hit50 - 50) > self::DRIFT_POINTS || abs($hit80 - 80) > self::DRIFT_POINTS);

            $table[] = [$row['bucket'], $row['n'], sprintf('%.1f %%', $hit50), sprintf('%.1f %%', $hit80), $judged ? ($drifted ? 'DRIFTED' : 'ok') : 'too few'];

            if ($drifted) {
                $this->logger->warning('Prediction windows drifted from their calibration target', [
                    'bucket' => $row['bucket'],
                    'samples' => $row['n'],
                    'hit50' => round($hit50, 1),
                    'hit80' => round($hit80, 1),
                    'days' => $days,
                ]);
            }
        }

        $io->table(['Bucket', 'Predictions', 'Inside 50 %', 'Inside 80 %', ''], $table);

        return self::SUCCESS;
    }
}
