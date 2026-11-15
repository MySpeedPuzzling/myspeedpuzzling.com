<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\LinkEqualImportRows;
use SpeedPuzzling\Web\Message\ParseImportSheet;
use SpeedPuzzling\Web\Message\RecordImportSheetParsed;
use SpeedPuzzling\Web\Message\RecordParsedImportRows;
use SpeedPuzzling\Web\Query\GetImportSheetRows;
use SpeedPuzzling\Web\Services\DataImport\ImportProgress;
use SpeedPuzzling\Web\Services\DataImport\ImportRowParser;
use SpeedPuzzling\Web\Services\DataImport\SheetLookupFactory;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetOptions;
use SpeedPuzzling\Web\Value\DataImportLimits;
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
 * newer one wrote. RecordImportSheetParsed ends it - the counts, the sheet's equal rows numbered and linked - and
 * LinkEqualImportRows links what is the same on two sheets.
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

            if ($mapping->needsAnAnswer()) {
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
            $after = 0;

            do {
                $page = $this->rows->page($message->sheetId, $after, DataImportLimits::ROWS_PER_BATCH);
                $records = [];

                foreach ($page as $row) {
                    $parsed = $this->parser->parse($row['number'], $row['cells'], $mapping, $options, $valueAnswers, $lookup);
                    $records[$row['id']] = $parsed->toRecord();
                    $after = $row['number'];

                    match (true) {
                        $parsed->isData() === false => $leftOut++,
                        $parsed->isUnderstood() => $understood++,
                        default => $needLook++,
                    };
                }

                if ($records !== [] && $this->ask(new RecordParsedImportRows($message->importId, $message->sheetId, $message->mappingVersion, $records)) !== true) {
                    return;
                }

                $this->progress->publish($message->importId, always: false);
            } while (count($page) === DataImportLimits::ROWS_PER_BATCH);

            if ($this->ask(new RecordImportSheetParsed($message->importId, $message->sheetId, $message->mappingVersion, $understood, $needLook, $leftOut)) === true) {
                $this->messageBus->dispatch(new LinkEqualImportRows($message->importId));
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

    private function ask(object $message): mixed
    {
        return $this->messageBus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }
}
