<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Exceptions\GoogleSheetUnreadable;
use SpeedPuzzling\Web\Message\SyncEventResultsSheet;
use SpeedPuzzling\Web\Query\GetEventResultsSheets;
use SpeedPuzzling\Web\Services\EventResultsImport\SarahDoesPuzzlesTabParser;
use SpeedPuzzling\Web\Services\EventResultsImport\GoogleSheetReader;
use SpeedPuzzling\Web\Value\EventResultsImport\EventTabProblem;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The hourly read of Sarah Does Puzzles' results sheet - the one organizer whose sheet is imported, in her sheet's format
 * (SarahDoesPuzzlesTabParser, docs/features/event-results-import/README.md). --dry-run reads and checks the sheet and
 * says what each tab would become - nothing is written.
 */
#[AsCommand(
    name: 'myspeedpuzzling:sync-sarah-does-puzzles-results',
    description: 'Reads Sarah Does Puzzles\' results sheet and imports every tab as an event with its results',
)]
final class SyncSarahDoesPuzzlesResultsConsoleCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetEventResultsSheets $getEventResultsSheets,
        private readonly GoogleSheetReader $reader,
        private readonly SarahDoesPuzzlesTabParser $parser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('sheet', null, InputOption::VALUE_REQUIRED, 'Only this sheet (its id on MySpeedPuzzling)');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Read and check only - write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $onlySheet = $input->getOption('sheet');
        $sheets = is_string($onlySheet) ? [$this->getEventResultsSheets->detail($onlySheet)] : $this->getEventResultsSheets->all();

        foreach ($sheets as $sheet) {
            $sheetId = $sheet['id'];
            assert(is_string($sheetId));

            if ($input->getOption('dry-run') === true) {
                $spreadsheetId = $sheet['spreadsheetId'];
                assert(is_string($spreadsheetId));
                $this->dryRun($io, $sheetId, $spreadsheetId);

                continue;
            }

            $this->messageBus->dispatch(new SyncEventResultsSheet($sheetId));
            $this->report($io, $this->getEventResultsSheets->detail($sheetId));
        }

        return self::SUCCESS;
    }

    private function dryRun(SymfonyStyle $io, string $sheetId, string $spreadsheetId): void
    {
        $io->section('Sheet ' . $sheetId . ' (dry run)');

        try {
            $tabs = $this->reader->tabs($spreadsheetId);
        } catch (GoogleSheetUnreadable $e) {
            $io->error($e->getMessage());

            return;
        }

        $rows = [];

        foreach ($tabs as $tab) {
            try {
                $parsed = $this->parser->parse($this->reader->csv($spreadsheetId, $tab->gid));
            } catch (GoogleSheetUnreadable $e) {
                $rows[] = [$tab->gid, $tab->title, 'unreadable', '-', $e->getMessage()];

                continue;
            }

            $outcome = match (true) {
                $parsed->isImportable() => 'importable (puzzle checked on import)',
                $parsed->waitsForPuzzle() => 'waiting_for_puzzle',
                default => 'problem',
            };

            $rows[] = [
                $tab->gid,
                $tab->title,
                $outcome,
                (string) count($parsed->rows),
                implode(', ', array_map(static fn (EventTabProblem $problem): string => self::describe($problem->toArray()), $parsed->problems)),
            ];
        }

        $io->table(['gid', 'Tab', 'Would be', 'Teams', 'Problems'], $rows);
    }

    /**
     * @param array<string, mixed> $sheet
     */
    private function report(SymfonyStyle $io, array $sheet): void
    {
        $io->section(sprintf('Sheet %s', is_string($sheet['id']) ? $sheet['id'] : ''));

        if (is_string($sheet['lastFetchError'])) {
            $io->warning($sheet['lastFetchError']);
        }

        $tabs = is_array($sheet['tabs']) ? $sheet['tabs'] : [];
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

        $io->table(['gid', 'Tab', 'Status', 'Results', 'Problems'], $rows);
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
