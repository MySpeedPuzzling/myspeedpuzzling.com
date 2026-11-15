<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\BeginImportSheetParse;
use SpeedPuzzling\Web\Message\BuildImportPuzzles;
use SpeedPuzzling\Web\Message\LinkEqualImportRows;
use SpeedPuzzling\Web\Message\ParseImportSheet;
use SpeedPuzzling\Web\Message\RecordEqualImportRows;
use SpeedPuzzling\Web\Message\RecordImportSheetParsed;
use SpeedPuzzling\Web\Message\RecordParsedImportRows;
use SpeedPuzzling\Web\Query\GetImportSheetRows;
use SpeedPuzzling\Web\Services\DataImport\ImportEqualRows;
use SpeedPuzzling\Web\Services\DataImport\ImportProgress;
use SpeedPuzzling\Web\Services\DataImport\ImportRowAnswers;
use SpeedPuzzling\Web\Services\DataImport\ImportRowParser;
use SpeedPuzzling\Web\Services\DataImport\PendingQuestionRows;
use SpeedPuzzling\Web\Services\DataImport\SheetLookupFactory;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetOptions;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Parses one sheet's rows for one version of its mapping (docs/features/data-import/columns.md "Parsing").
 *
 * Outside a transaction (ParseImportSheet), in pages of DataImportLimits::ROWS_PER_BATCH: a page of raw cells is
 * read, parsed in memory (ImportRowParser - pure) and written by RecordParsedImportRows in one short transaction;
 * memory does not grow with the sheet. Every write is checked against the sheet's current mapping version under the
 * import's lock, on fresh state: a job of an older version stops at its next batch and has overwritten nothing a
 * newer one wrote. RecordImportSheetParsed ends it - the counts, the sheet's equal rows numbered and linked -
 * LinkEqualImportRows links what is the same on two sheets, and BuildImportPuzzles (queued) builds and pairs the
 * import's distinct puzzles.
 *
 * A sheet that still has a question is not parsed. Nothing is thrown on (the queue has no retries): a failure is
 * ours, logged as an error - the sheet stays "not parsed for this version".
 */
#[AsMessageHandler]
readonly final class ParseImportSheetHandler
{
    public function __construct(
        private GetImportSheetRows $rows,
        private ImportRowParser $parser,
        private SheetLookupFactory $lookupFactory,
        private MessageBusInterface $messageBus,
        private ImportProgress $progress,
        private LoggerInterface $logger,
        /** An argument only so that a test can page through groups one at a time */
        private int $equalKeysPerPage = DataImportLimits::EQUAL_KEYS_PER_PAGE,
    ) {
    }

    public function __invoke(ParseImportSheet $message): void
    {
        try {
            $sheets = [];

            foreach ($this->rows->sheetsBeingSetUp($message->importId) as $sheet) {
                $sheets[$sheet['id']] = $sheet;
            }

            $sheet = $sheets[$message->sheetId] ?? null;

            if ($sheet === null || $sheet['mappingVersion'] !== $message->mappingVersion || $sheet['mapping'] === null) {
                return;
            }

            $mapping = ImportMapping::fromArray($sheet['mapping']);

            // Once per sheet and version: a second delivery, the safety net or a job beside a running one stops here.
            // A sheet with a question is parsed too: only the rows the question decides wait (PendingQuestionRows)
            if ($this->ask(new BeginImportSheetParse($message->importId, $message->sheetId, $message->mappingVersion)) !== true) {
                return;
            }

            $options = ImportSheetOptions::fromArray($sheet['options']);
            $valueAnswers = $sheet['valueAnswers'] ?? [];
            $list = $options->lookupSheetId === null ? null : $sheets[$options->lookupSheetId] ?? null;
            $lookup = $list === null || $list['mapping'] === null || $mapping->isImported() === false
                ? null
                : $this->lookupFactory->build(ImportMapping::fromArray($list['mapping']), $this->rows->rows($list['id'], DataImportLimits::MAX_ROWS));

            $understood = 0;
            $needLook = 0;
            $leftOut = 0;
            $waiting = 0;
            $after = 0;

            // A page is bounded by rows and by bytes (GetImportSheetRows::page()): the last one is the empty one
            while (($page = $this->rows->page($message->sheetId, $after, DataImportLimits::ROWS_PER_BATCH)) !== []) {
                $records = [];

                foreach ($page as $row) {
                    $parsed = ImportRowAnswers::settled(
                        PendingQuestionRows::mark($this->parser->parse($row['number'], $row['cells'], $mapping, $options, $valueAnswers, $lookup, $row['answers']), $row['cells'], $mapping),
                        $row['answers'],
                    );
                    $records[$row['id']] = $parsed->toRecord();
                    $after = $row['number'];

                    match (true) {
                        $parsed->isData() === false => $leftOut++,
                        $parsed->isWaiting() => $waiting++,
                        $parsed->isUnderstood() => $understood++,
                        default => $needLook++,
                    };
                }

                if ($this->ask(new RecordParsedImportRows($message->importId, $message->sheetId, $message->mappingVersion, $records)) !== true) {
                    return;
                }

                $this->progress->publish($message->importId, always: false);
            }

            if ($this->numberEqualRows($message) === false) {
                return;
            }

            if ($this->ask(new RecordImportSheetParsed($message->importId, $message->sheetId, $message->mappingVersion, $understood, $needLook, $leftOut, $waiting)) === true) {
                $this->messageBus->dispatch(new LinkEqualImportRows($message->importId));
                // The import's distinct puzzles, from the rows as they are parsed now - and their pairing with the
                // catalogue (docs/features/data-import/pairing.md). On the queue: a job of its own
                $this->messageBus->dispatch(new BuildImportPuzzles($message->importId));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Parsing a sheet of an import failed on our side', [
                'import_id' => $message->importId,
                'sheet_id' => $message->sheetId,
                'mapping_version' => $message->mappingVersion,
                'exception' => $e,
            ]);
        } finally {
            $this->progress->publish($message->importId);
        }
    }

    /**
     * Rows of the sheet that are equal in all a key holds are numbered and linked (ImportEqualRows): whole groups,
     * a page of keys at a time, from each group's light facts - the rows' cells are not read. Written in batches
     * bounded like the parse's own. The groups are found by `equal_key`, which the numbering never changes: done
     * twice, it gives the same.
     *
     * @return bool false: the sheet's mapping moved on - nothing more is written
     */
    private function numberEqualRows(ParseImportSheet $message): bool
    {
        $after = '';

        while (($keys = $this->rows->sharedEqualKeys($message->sheetId, $after, $this->equalKeysPerPage)) !== []) {
            $after = $keys[array_key_last($keys)];
            $groups = [];

            foreach ($this->rows->rowsEqualBy($message->sheetId, $keys) as $row) {
                $groups[$row['equalKey']][$row['id']] = $row;
            }

            $batch = [];
            $bytes = 0;

            foreach ($groups as $rows) {
                $facts = [];

                foreach ($rows as $id => $row) {
                    $attempt = $row['parsed']['attempt'] ?? null;
                    $content = $row['parsed']['content'] ?? '';
                    $facts[] = ['id' => $id, 'attempt' => is_int($attempt) ? $attempt : null, 'content' => is_string($content) ? $content : '', 'rowNumber' => $row['rowNumber']];
                }

                foreach (ImportEqualRows::number($facts) as $id => $numbered) {
                    $row = $rows[$id];
                    $isResult = $row['kind'] === DataImportRowKind::Result->value || $row['kind'] === DataImportRowKind::Both->value;
                    $batch[$id] = [
                        'occurrence' => $numbered['occurrence'],
                        // Only a result's key holds the occurrence: two rows for one owned puzzle are one puzzle
                        'sourceKey' => $isResult && $row['puzzleKey'] !== null ? ImportEqualRows::resultKey($row['parsed'], $row['puzzleKey'], $numbered['occurrence']) : null,
                        'sameAs' => $numbered['sameAs'],
                    ];
                    $bytes += $row['bytes'];

                    if (count($batch) >= DataImportLimits::ROWS_PER_BATCH || $bytes >= DataImportLimits::BYTES_PER_BATCH) {
                        if ($this->ask(new RecordEqualImportRows($message->importId, $message->sheetId, $message->mappingVersion, $batch)) !== true) {
                            return false;
                        }

                        $batch = [];
                        $bytes = 0;
                    }
                }
            }

            if ($batch !== [] && $this->ask(new RecordEqualImportRows($message->importId, $message->sheetId, $message->mappingVersion, $batch)) !== true) {
                return false;
            }
        }

        return true;
    }

    private function ask(object $message): mixed
    {
        return $this->messageBus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }
}
