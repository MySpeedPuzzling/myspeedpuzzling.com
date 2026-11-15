<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\ProposeImportMappings;
use SpeedPuzzling\Web\Message\RecordImportMappings;
use SpeedPuzzling\Web\Query\GetImportLayouts;
use SpeedPuzzling\Web\Query\GetImportSheetRows;
use SpeedPuzzling\Web\Services\DataImport\ImportLayoutMatcher;
use SpeedPuzzling\Web\Services\DataImport\ImportProgress;
use SpeedPuzzling\Web\Services\DataImport\ImportSheetAnalyser;
use SpeedPuzzling\Web\Services\DataImport\SheetLookupFactory;
use SpeedPuzzling\Web\Value\DataImport\SavedImportLayout;
use SpeedPuzzling\Web\Value\DataImportLimits;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Proposes the mapping of every sheet of a read import (docs/features/data-import/columns.md "Proposing"):
 *
 * 1. per sheet, from a sample of its rows: its table, whether it is a known export, its columns, its kind
 *    (ImportSheetAnalyser) - and, where the player set up a sheet with these headers before, what they said then
 *    (ImportLayoutMatcher, docs/features/data-import/layouts.md);
 * 2. across the workbook: the puzzle list the results sheets point to (SheetLookupFactory::link());
 * 3. per sheet, over up to DataImportLimits::COLUMN_DECISION_ROWS rows: how its times and dates are read, and what
 *    must be asked (ImportColumnDecisions) - a remembered layout answers what it can;
 * 4. RecordImportMappings stores it all as version 1 and queues the parsing of every sheet that needs no answer.
 *
 * Outside a transaction (ProposeImportMappings); nothing is loaded whole. Nothing is thrown on: the queue has no
 * retries - a failure is ours, logged as an error, and the import stays read with its sheets unmapped.
 */
#[AsMessageHandler]
readonly final class ProposeImportMappingsHandler
{
    public function __construct(
        private GetImportSheetRows $rows,
        private ImportSheetAnalyser $analyser,
        private ImportLayoutMatcher $layoutMatcher,
        private GetImportLayouts $getImportLayouts,
        private SheetLookupFactory $lookupFactory,
        private MessageBusInterface $messageBus,
        private ImportProgress $progress,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProposeImportMappings $message): void
    {
        try {
            $proposals = [];
            $titles = [];
            $names = [];
            $lists = [];
            $layouts = $this->savedLayouts($message->importId);

            foreach ($this->rows->sheetsBeingSetUp($message->importId) as $sheet) {
                if ($sheet['mappingVersion'] !== 0) {
                    continue;
                }

                // One sample in memory at a time: a hundred sheets' samples together can be most of an import
                $sample = $this->rows->sample($sheet['id']);
                $proposal = $this->analyser->analyse($sample);

                if ($layouts !== []) {
                    $proposal = $this->layoutMatcher->apply($sample->title, $proposal, $layouts);
                }

                $proposals[$sheet['id']] = $proposal;
                $titles[$sheet['id']] = $sample->title;

                if (SheetLookupFactory::needsALookup($proposal->mapping)) {
                    $names[$sheet['id']] = $this->lookupFactory->names($proposal->mapping, $sample->rows);
                }
            }

            if ($proposals === []) {
                return;
            }

            foreach ($names === [] ? [] : $proposals as $sheetId => $proposal) {
                if (SheetLookupFactory::couldBeTheList($proposal->mapping)) {
                    $lists[$sheetId] = $this->lookupFactory->build($proposal->mapping, $this->rows->rows($sheetId, DataImportLimits::MAX_ROWS));
                }
            }

            $recorded = [];
            $contents = [];

            foreach ($layouts as $layout) {
                $contents[$layout->id] = $layout->content;
            }

            $linked = ImportLayoutMatcher::withRememberedLookups(SheetLookupFactory::link($proposals, $names, $lists), $titles, $layouts);

            foreach ($linked as $sheetId => $proposal) {
                $table = $proposal->mapping->table();
                [$options, $questions] = $this->layoutMatcher->settle(
                    $proposal->mapping,
                    $proposal->options,
                    $titles[$sheetId],
                    fn (): iterable => $this->rows->rows($sheetId, DataImportLimits::COLUMN_DECISION_ROWS, $table->firstRow ?? 1, $table?->lastRow),
                    $contents[$proposal->mapping->remembered->id ?? ''] ?? null,
                );
                $proposal = $proposal->with($proposal->mapping, $options, $questions);
                $recorded[$sheetId] = [
                    'mapping' => $proposal->mapping->toArray(),
                    'options' => $proposal->options->toArray(),
                    'valueAnswers' => $proposal->valueAnswers,
                    'headerSignature' => $proposal->headerSignature,
                ];
            }

            $this->messageBus->dispatch(new RecordImportMappings($message->importId, $recorded));
        } catch (\Throwable $e) {
            $this->logger->error('Proposing the mappings of an import\'s sheets failed on our side', [
                'import_id' => $message->importId,
                'exception' => $e,
            ]);
        } finally {
            $this->progress->publish($message->importId);
        }
    }

    /**
     * What the player set up before - or nothing, when it can not be read: the import then proposes as it does for
     * anybody, which is never worse than no proposal.
     *
     * @return list<SavedImportLayout>
     */
    private function savedLayouts(string $importId): array
    {
        try {
            return $this->getImportLayouts->ofImportsPlayer($importId);
        } catch (\Throwable $e) {
            $this->logger->error('The saved layouts of an import\'s player could not be read', [
                'import_id' => $importId,
                'exception' => $e,
            ]);

            return [];
        }
    }
}
