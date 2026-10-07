<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Results\SuspiciousTimeScanSummary;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeClassifier;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeScan;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Twice a day (docs/features/suspicious-time-review.md, "Cron"): the scan, then the notice run. Run it with --dry-run
 * before deploying a new SuspiciousTimeClassifier::VERSION - it counts what the current code would raise without
 * writing anything; the times themselves are reviewed in the moderator queue.
 *
 * Once at go-live, instead of the first cron run: --existing-marks-told-by-hand. Every time flagged then was e-mailed
 * by hand, so the notices of that run are recorded as already sent (via manual_email) - neither the banner nor the
 * e-mail ever mentions them ("Go-live runbook" in the doc).
 */
#[AsCommand(
    name: 'myspeedpuzzling:detect-suspicious-times',
    description: 'Checks times against what each player does and opens cases for verification (never marks a time), reconciles flags set by SQL, then tells players about times awaiting verification',
)]
final class DetectSuspiciousTimesConsoleCommand extends Command
{
    public function __construct(
        readonly private SuspiciousTimeScan $scan,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Check everything the scan would, write nothing, tell nobody');
        $this->addOption('existing-marks-told-by-hand', null, InputOption::VALUE_NONE, 'Once at go-live: every flagged time was e-mailed by hand - record the notices of this run as already sent, so nobody is told again');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run') === true;
        $toldByHand = $input->getOption('existing-marks-told-by-hand') === true;

        if ($dryRun && $toldByHand) {
            $io->error('A dry run tells nobody - --existing-marks-told-by-hand belongs to the real go-live run.');

            return self::INVALID;
        }

        $io->writeln(sprintf('Time verification, detector version %d%s', SuspiciousTimeClassifier::VERSION, $dryRun ? ' - dry run, nothing is written' : ''));

        $result = $this->scan->run($dryRun, $toldByHand);
        $summary = $result->detection;

        if ($summary === null) {
            $io->error('The scan failed (logged) - nothing was stored.');

            return self::FAILURE;
        }

        $this->printSummary($io, $summary);
        $io->writeln(sprintf('Peak memory: %.0f MB', memory_get_peak_usage(true) / 1048576));

        if ($dryRun) {
            $io->success('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        if ($result->noticesFailed) {
            // A plain run would tell the players about marks they were e-mailed by hand - the go-live run is repeated
            $io->error($toldByHand
                ? 'The notice run failed (logged) - nothing was recorded as told by hand. Run this command again with --existing-marks-told-by-hand before the cron runs: a plain run would tell those players again.'
                : 'The notice run failed (logged) - the next run tells the players.');

            return self::FAILURE;
        }

        if ($toldByHand) {
            $io->success(sprintf('Marks recorded as told by hand (nobody is told again): %d', $result->notices ?? 0));

            return self::SUCCESS;
        }

        $io->success(sprintf('Players told about times awaiting verification: %d', $result->notices ?? 0));

        return self::SUCCESS;
    }

    private function printSummary(SymfonyStyle $io, SuspiciousTimeScanSummary $summary): void
    {
        $io->table(
            ['References', 'Checked', 'Clear', 'No data', 'Raised', 'Raised by direction', 'Raised by tier', 'Raised by expectation'],
            [[
                $summary->references,
                $summary->checked,
                $summary->clear,
                $summary->noData,
                $summary->raised,
                self::counts($summary->raisedByDirection),
                self::counts($summary->raisedByTier),
                self::counts($summary->raisedBySource),
            ]],
        );

        $io->table(
            [$summary->dryRun ? 'Cases: would be new' : 'Cases: new', 'refreshed', 'reopened', 'gone', 'Marked outside the app', 'Unmarked outside the app', 'Changed marks: unmarked', 'back to moderators'],
            [[
                $summary->newCases,
                $summary->refreshedCases,
                $summary->reopenedCases,
                $summary->goneCases,
                $summary->markedOutsideApp,
                $summary->unmarkedOutsideApp,
                $summary->changedMarksUnmarked,
                $summary->changedMarksToModerators,
            ]],
        );
    }

    /**
     * @param array<string, int> $counts
     */
    private static function counts(array $counts): string
    {
        $parts = [];

        foreach ($counts as $key => $count) {
            $parts[] = "{$key} {$count}";
        }

        return implode(', ', $parts);
    }
}
