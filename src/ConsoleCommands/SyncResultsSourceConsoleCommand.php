<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Exceptions\GoogleSheetUnreadable;
use SpeedPuzzling\Web\Message\SyncEventResultsSheet;
use SpeedPuzzling\Web\Query\GetEventResultsSheets;
use SpeedPuzzling\Web\Services\EventResultsImport\ResultsSourceReader;
use SpeedPuzzling\Web\Value\EventResultsImport\EventTabProblem;
use SpeedPuzzling\Web\Value\EventResultsImport\ParsedEvent;
use SpeedPuzzling\Web\Value\EventResultsImport\ParsedEventRow;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The on-demand read (no cron) of an organization's results source - one, or every one - in the source's format
 * (docs/features/event-results-import/README.md, results-import-page.md). For admins and ops; it dispatches
 * SyncEventResultsSheet. --dry-run reads and checks the sheet through the same reader and says what each event would
 * become - nothing is written.
 */
#[AsCommand(
    name: 'myspeedpuzzling:sync-results-source',
    description: 'Reads a results source (or every one) and imports its events with their results',
)]
final class SyncResultsSourceConsoleCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetEventResultsSheets $getEventResultsSheets,
        private readonly ResultsSourceReader $sourceReader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('sourceId', InputArgument::OPTIONAL, 'Only this source (its id on MySpeedPuzzling) - every source when left out');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Read and check only - write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $onlySource = $input->getArgument('sourceId');
        $sources = is_string($onlySource) ? [$this->getEventResultsSheets->detail($onlySource)] : $this->getEventResultsSheets->all();

        foreach ($sources as $source) {
            $sourceId = $source['id'];
            assert(is_string($sourceId));

            if ($input->getOption('dry-run') === true) {
                $spreadsheetId = $source['spreadsheetId'];
                $format = $source['format'];
                assert(is_string($spreadsheetId) && is_string($format));
                $this->dryRun($io, $sourceId, $spreadsheetId, $format);

                continue;
            }

            $this->messageBus->dispatch(new SyncEventResultsSheet($sourceId));
            $this->report($io, $this->getEventResultsSheets->detail($sourceId));
        }

        return self::SUCCESS;
    }

    private function dryRun(SymfonyStyle $io, string $sourceId, string $spreadsheetId, string $format): void
    {
        $io->section('Source ' . $sourceId . ' (' . $format . ', dry run)');

        try {
            $read = $this->sourceReader->read($spreadsheetId, $format);
        } catch (GoogleSheetUnreadable $e) {
            $io->error($e->getMessage());

            return;
        }

        $rows = [];

        foreach ($read->events as $event) {
            $outcome = match (true) {
                // Whether the puzzle exists and the unfinished teams' pieces placed fit it is found out by the import
                $event->isImportable() => 'importable (puzzle checked on import)',
                $event->waitsForPuzzle() => 'waiting_for_puzzle',
                default => 'problem',
            };

            $rows[] = [
                $event->key,
                $event->title,
                $outcome,
                self::teams($event),
                implode(', ', array_map(static fn (EventTabProblem $problem): string => self::describe($problem->toArray()), $event->problems)),
            ];
        }

        foreach ($read->snapshot->unreadableTabs as $tab) {
            $rows[] = [$tab->gid, $tab->title, 'unreadable', '-', $tab->reason];
        }

        $io->table(['Key', 'Event', 'Would be', 'Teams', 'Problems'], $rows);
    }

    /**
     * "5", or "5 (2 unfinished)" - teams with pieces placed instead of a time (README "Unfinished results (D9)")
     */
    private static function teams(ParsedEvent $event): string
    {
        $unfinished = count(array_filter($event->rows, static fn (ParsedEventRow $row): bool => $row->isUnfinished()));

        return count($event->rows) . ($unfinished > 0 ? sprintf(' (%d unfinished)', $unfinished) : '');
    }

    /**
     * @param array<string, mixed> $source
     */
    private function report(SymfonyStyle $io, array $source): void
    {
        $io->section(sprintf('Source %s', is_string($source['id']) ? $source['id'] : ''));

        if (is_string($source['lastFetchError'])) {
            $io->warning($source['lastFetchError']);
        }

        $tabs = is_array($source['tabs']) ? $source['tabs'] : [];
        $rows = [];

        foreach ($tabs as $tab) {
            assert(is_array($tab));
            $problems = is_array($tab['problems']) ? $tab['problems'] : [];

            $rows[] = [
                $tab['gid'],
                $tab['title'],
                $tab['status'],
                $tab['results'],
                implode(', ', array_map(static fn (mixed $problem): string => is_array($problem) ? self::describe($problem) : '', $problems)),
            ];
        }

        $io->table(['Key', 'Event', 'Status', 'Results', 'Problems'], $rows);
    }

    /**
     * @param array<mixed> $problem
     */
    private static function describe(array $problem): string
    {
        $code = is_string($problem['code'] ?? null) ? $problem['code'] : '?';
        $detail = is_string($problem['detail'] ?? null) ? ' (' . $problem['detail'] . ')' : '';
        $line = is_int($problem['line'] ?? null) ? ' @' . $problem['line'] : '';

        return $code . $detail . $line;
    }
}
