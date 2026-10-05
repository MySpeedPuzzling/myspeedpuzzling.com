<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Message\CanonicalizePuzzleCodes;
use SpeedPuzzling\Web\Query\GetPuzzleStoredCodes;
use SpeedPuzzling\Web\Value\PuzzleCodesCleanup;
use SpeedPuzzling\Web\Value\PuzzleCodesCleanupReason;
use SpeedPuzzling\Web\Value\PuzzleCodesReportRow;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Writes every puzzle's EANs and brand codes in their canonical form where that only changes how they are written -
 * separators, spaces, leading zeros, case (PuzzleCodesCleanup). Everything that would change a value is listed in
 * the report CSV instead, to be filed as change proposals (docs/features/puzzle-names/README.md, "Writing names and
 * codes"). A dry run by default: it reads, counts and reports; --write applies the format-only changes, in batches
 * locked for update, and every batch appends each changed field with its value before and after to the undo file
 * before it commits (CanonicalizePuzzleCodesHandler; NULL written as \N). The undo file must be new - a path that
 * exists is refused, so a re-run never overwrites the values of an interrupted run: use a new path per run. Safe to
 * interrupt and to run again - a second run finds nothing to write.
 */
#[AsCommand(
    'myspeedpuzzling:canonicalize-puzzle-codes',
    'Writes puzzle codes in their canonical form (format-only changes) and reports the rest. Dry run unless --write.',
)]
final class CanonicalizePuzzleCodesConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private GetPuzzleStoredCodes $getPuzzleStoredCodes,
        readonly private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('write', null, InputOption::VALUE_NONE, 'Apply the format-only changes (without it nothing is written)')
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Path of the report CSV (what a person decides)')
            ->addOption('undo', null, InputOption::VALUE_REQUIRED, 'Path of a new undo CSV - every written field before and after, NULL as \\N (required with --write, never an existing file)')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Puzzles per transaction', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $write = $input->getOption('write') === true;
        /** @var null|string $reportPath */
        $reportPath = $input->getOption('report');
        /** @var null|string $undoPath */
        $undoPath = $input->getOption('undo');
        /** @var string $batchOption */
        $batchOption = $input->getOption('batch');
        $batchSize = max(1, (int) $batchOption);
        $started = microtime(true);

        if ($write && $undoPath === null) {
            $io->error('--write needs --undo=<path>: every written field is kept there with its value before.');

            return self::INVALID;
        }

        if ($write) {
            // A new file only: a re-run with the path of an interrupted run would wipe the values it saved
            if (file_exists($undoPath)) {
                $io->error(sprintf('The undo file "%s" exists already - use a new path for every run, it keeps the values before.', $undoPath));

                return self::INVALID;
            }

            $undo = @fopen($undoPath, 'xb');

            if ($undo === false) {
                $io->error(sprintf('The undo file "%s" cannot be created.', $undoPath));

                return self::FAILURE;
            }

            fputcsv($undo, ['puzzle_id', 'field', 'before', 'after'], escape: '');
            fclose($undo);
        }

        $report = null;

        if ($reportPath !== null) {
            $report = fopen($reportPath, 'wb');

            if ($report === false) {
                $io->error(sprintf('The report "%s" cannot be written.', $reportPath));

                return self::FAILURE;
            }

            fputcsv($report, PuzzleCodesReportRow::csvHeader(), escape: '');
        }

        $io->writeln($write ? 'Writing the format-only changes.' : 'Dry run - nothing is written (--write applies the format-only changes).');

        $progressBar = new ProgressBar($output, $this->getPuzzleStoredCodes->count());
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s% %memory:6s%');

        $formatOnly = ['ean' => 0, 'identification_number' => 0];
        $written = ['ean' => 0, 'identification_number' => 0];
        $reasons = array_fill_keys(array_map(static fn (PuzzleCodesCleanupReason $reason): string => $reason->value, PuzzleCodesCleanupReason::cases()), 0);
        $afterId = null;

        while (($rows = $this->getPuzzleStoredCodes->after($afterId, $batchSize)) !== []) {
            $writableIds = [];

            foreach ($rows as $row) {
                $cleanup = PuzzleCodesCleanup::of($row['id'], $row['name'], $row['ean'], $row['identification_number']);

                foreach ($cleanup->reportRows as $reportRow) {
                    $reasons[$reportRow->reason->value]++;

                    if ($report !== null) {
                        fputcsv($report, $reportRow->toCsvRow(), escape: '');
                    }
                }

                $formatOnly['ean'] += $cleanup->writeEans ? 1 : 0;
                $formatOnly['identification_number'] += $cleanup->writeBrandCodes ? 1 : 0;

                if ($cleanup->writeEans || $cleanup->writeBrandCodes) {
                    $writableIds[] = $row['id'];
                }
            }

            if ($write && $writableIds !== []) {
                // The handler appends the batch's undo rows before the batch commits
                $envelope = $this->messageBus->dispatch(new CanonicalizePuzzleCodes($writableIds, $undoPath));

                /** @var list<array{puzzleId: string, field: string, before: null|string, after: null|string}> $writtenInBatch */
                $writtenInBatch = $envelope->last(HandledStamp::class)?->getResult() ?? [];

                foreach ($writtenInBatch as $change) {
                    $written[$change['field']]++;
                }

                // The doctrine_transaction middleware has committed the batch: the identity map stays at one batch
                $this->entityManager->clear();
            }

            $afterId = $rows[array_key_last($rows)]['id'];
            $progressBar->advance(count($rows));
        }

        $progressBar->finish();
        $io->newLine(2);

        if ($report !== null) {
            fclose($report);
        }


        $io->table(['Field', 'Format-only changes', 'Written'], [
            ['EAN', $formatOnly['ean'], $write ? $written['ean'] : '- (dry run)'],
            ['Brand code', $formatOnly['identification_number'], $write ? $written['identification_number'] : '- (dry run)'],
        ]);

        $io->table(['Report reason', 'Rows'], array_map(
            static fn (string $reason, int $count): array => [$reason, $count],
            array_keys($reasons),
            $reasons,
        ));

        $io->writeln(sprintf(
            'Took %.1f s, memory peak %s.%s%s',
            microtime(true) - $started,
            Helper::formatMemory(memory_get_peak_usage(true)),
            $reportPath !== null ? sprintf(' Report: %s.', $reportPath) : '',
            $write ? sprintf(' Undo: %s.', $undoPath) : '',
        ));

        return self::SUCCESS;
    }
}
