<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\RecordSharedImportMappings;
use SpeedPuzzling\Web\Message\ShareImportLayout;
use SpeedPuzzling\Web\Query\GetImportLayouts;
use SpeedPuzzling\Web\Query\GetImportSheetRows;
use SpeedPuzzling\Web\Services\DataImport\ImportLayoutMatcher;
use SpeedPuzzling\Web\Services\DataImport\ImportProgress;
use SpeedPuzzling\Web\Services\DataImport\ImportSheetAnalyser;
use SpeedPuzzling\Web\Value\DataImport\ColumnCertainty;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetOptions;
use SpeedPuzzling\Web\Value\DataImport\SavedImportLayout;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\DataImportSheetKind;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Lets the sheets of a workbook follow the one its player confirmed (docs/features/data-import/layouts.md "One
 * workbook"). For every sheet with a mapping that the player has not confirmed themselves:
 *
 * 1. the sheet is recognised again from its own rows (ImportSheetAnalyser) - what it says by itself is what the
 *    layout is checked against, never what an earlier confirmation left on it;
 * 2. the layout is laid over it by the same rules as in a later import (ImportLayoutMatcher::apply(): the columns;
 *    what the sheet is only where the sheet agrees), and its times and dates are settled from its own rows - the
 *    layout answers what it may;
 * 3. RecordSharedImportMappings stores what changed as the sheet's next version and queues its parsing.
 *
 * Outside a transaction; nothing is thrown on: a sheet that can not be worked out keeps its mapping and is read as
 * it is.
 */
#[AsMessageHandler]
readonly final class ShareImportLayoutHandler
{
    public function __construct(
        private GetImportSheetRows $rows,
        private GetImportLayouts $getImportLayouts,
        private ImportSheetAnalyser $analyser,
        private ImportLayoutMatcher $layoutMatcher,
        private MessageBusInterface $messageBus,
        private ImportProgress $progress,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ShareImportLayout $message): void
    {
        try {
            $layout = null;

            foreach ($this->getImportLayouts->ofImportsPlayer($message->importId) as $saved) {
                if ($saved->id === strtolower($message->layoutId)) {
                    $layout = $saved;
                }
            }

            $sheets = [];

            foreach ($this->rows->sheetsBeingSetUp($message->importId) as $sheet) {
                if ($sheet['mappingVersion'] === 0 || $sheet['confirmedByPlayer']) {
                    continue;
                }

                $changed = null;

                try {
                    // A layout forgotten meanwhile changes nothing: the sheets are read as they are
                    $changed = $layout === null ? null : $this->followed($sheet, $layout);
                } catch (\Throwable $e) {
                    $this->logger->error('A sheet of an import could not follow the sheet its player confirmed', [
                        'import_id' => $message->importId,
                        'sheet_id' => $sheet['id'],
                        'exception' => $e,
                    ]);
                }

                $sheets[$sheet['id']] = ['version' => $sheet['mappingVersion'], 'mapping' => $changed[0] ?? null, 'options' => $changed[1] ?? null];
            }

            if ($sheets !== []) {
                $this->messageBus->dispatch(new RecordSharedImportMappings($message->importId, $sheets));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Sharing a confirmed layout with the other sheets of an import failed on our side', [
                'import_id' => $message->importId,
                'exception' => $e,
            ]);
        } finally {
            $this->progress->publish($message->importId);
        }
    }

    /**
     * @param array{id: string, mapping: null|array<mixed>, options: null|array<mixed>, ...} $sheet
     *
     * @return null|array{array<string, mixed>, array<string, mixed>} the sheet's new mapping and options - null:
     *         the layout does not fit it, or nothing would change
     */
    private function followed(array $sheet, SavedImportLayout $layout): null|array
    {
        $current = ImportMapping::fromArray($sheet['mapping'] ?? []);
        $currentOptions = ImportSheetOptions::fromArray($sheet['options']);

        if (ImportLayoutMatcher::matching($current, [$layout]) === null) {
            return null;
        }

        $sample = $this->rows->sample($sheet['id']);
        $own = $this->analyser->analyse($sample);
        $proposal = $this->layoutMatcher->apply($sample->title, $own, [$layout], $sample->rows);
        $mapping = $proposal->mapping;

        if ($mapping->remembered === null) {
            return null;
        }

        // What only the whole workbook told stays: the list other sheets look puzzles up in, and the list this one
        // looks them up in
        if ($current->kind === DataImportSheetKind::PuzzleList && $mapping->kind === DataImportSheetKind::Owned) {
            $mapping = $mapping->withKind(DataImportSheetKind::PuzzleList, ColumnCertainty::Certain);
        }

        $table = $mapping->table();
        [$options, $questions] = $this->layoutMatcher->settle(
            $mapping,
            $proposal->options->withLookupSheet($currentOptions->lookupSheetId)->withCollectionLabel($currentOptions->collectionLabel),
            $sample->title,
            fn (): iterable => $this->rows->rows($sheet['id'], DataImportLimits::COLUMN_DECISION_ROWS, $table->firstRow ?? 1, $table?->lastRow),
            $layout->content,
        );
        $mapping = $mapping->withQuestions($questions);

        if (self::canonical($mapping->toArray()) === self::canonical($sheet['mapping'] ?? []) && self::canonical($options->toArray()) === self::canonical($sheet['options'] ?? [])) {
            return null;
        }

        return [$mapping->toArray(), $options->toArray()];
    }

    /**
     * A stored JSON object and the same one built anew, comparable: the order of keys is nobody's word.
     *
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function canonical(array $value): array
    {
        if (array_is_list($value) === false) {
            ksort($value);
        }

        return array_map(static fn (mixed $item): mixed => is_array($item) ? self::canonical($item) : $item, $value);
    }
}
