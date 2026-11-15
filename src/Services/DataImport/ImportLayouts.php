<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportSheet;
use SpeedPuzzling\Web\Entity\ImportLayout;
use SpeedPuzzling\Web\Exceptions\InvalidImportMapping;
use SpeedPuzzling\Web\Message\ParseImportSheet;
use SpeedPuzzling\Web\Query\GetImportSheetRows;
use SpeedPuzzling\Web\Repository\ImportLayoutRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImport\ColumnCertainty;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetOptions;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetProposal;
use SpeedPuzzling\Web\Value\DataImport\SavedImportLayout;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\DataImportSheetKind;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Remembers what a player confirmed for a sheet (docs/features/data-import/layouts.md) - called by
 * ConfirmImportMappingHandler once the sheet's new mapping stands, inside its transaction and under the import's
 * lock:
 *
 * 1. the sheet's layout is saved for the player - a new one, or the one of the same signature with the newer word;
 * 2. the other sheets of the same workbook with the same header row that the player has not confirmed themselves
 *    get the same mapping and answers at once, as a new version each, and are read again. A sheet the player
 *    confirmed is never touched.
 *
 * A sheet without a header row, and one the player left out, is not remembered.
 */
final readonly class ImportLayouts
{
    /** A player's saved layouts: one more replaces the one used longest ago */
    public const int MAX_PER_PLAYER = 50;
    /** Sheets of one workbook that follow a confirmed one in a single step - each is settled from its own rows */
    public const int MAX_SHEETS_SHARED = 30;

    private const string LOCK_NAME = 'import_layout';

    public function __construct(
        private ImportLayoutRepository $layoutRepository,
        private ImportLayoutMatcher $matcher,
        private GetImportSheetRows $rows,
        private MessageBusInterface $messageBus,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param ImportMapping $before the mapping the player's word was said about
     * @param list<DataImportSheet> $sheets every sheet of the import
     */
    public function confirmed(DataImport $import, DataImportSheet $sheet, ImportMapping $before, array $sheets): void
    {
        try {
            $mapping = ImportMapping::fromArray($sheet->mapping ?? []);
            $options = ImportSheetOptions::fromArray($sheet->options);
        } catch (InvalidImportMapping) {
            return;
        }

        $signature = ImportLayoutMatcher::signature($mapping);
        $content = ImportLayoutMatcher::contentOf($mapping, $options, self::lookupOf($options, $sheets));

        if ($signature === null || $content === null) {
            return;
        }

        // Two imports of one player confirmed at the same moment must not both make the layout of one signature
        $this->idLock->lockUntilCommit(Uuid::uuid5($import->player->id, self::LOCK_NAME));

        $now = $this->clock->now();
        $changesKnownExport = $mapping->layout !== null && ImportLayoutMatcher::differs($before, $mapping);
        $layout = $this->layoutRepository->findBySignature($import->player, $signature);

        // A known export nobody changed needs no layout: the export itself is recognised every time
        if ($layout === null && $mapping->layout !== null && $changesKnownExport === false) {
            return;
        }

        $headers = ImportLayoutMatcher::headerKeys($mapping);

        if ($layout === null) {
            $existing = $this->layoutRepository->ofPlayerOldestFirst($import->player);

            foreach (array_slice($existing, 0, max(0, count($existing) - self::MAX_PER_PLAYER + 1)) as $oldest) {
                $this->layoutRepository->delete($oldest);
            }

            $layout = new ImportLayout(
                Uuid::uuid7(),
                $import->player,
                $signature,
                self::nameOf($import, $sheet),
                $headers,
                $content->toArray(),
                $changesKnownExport,
                $now,
            );
            $this->layoutRepository->save($layout);
        } else {
            $layout->savedAgain($headers, $content, $changesKnownExport, $now);
        }

        $this->share($import, $sheet, $sheets, new SavedImportLayout(
            $layout->id->toString(),
            $layout->name,
            $signature,
            $headers,
            $content,
            $layout->changesKnownExport,
            $now,
        ));
    }

    /**
     * Within one workbook: the sheets with the very same header row follow the confirmed one.
     *
     * @param list<DataImportSheet> $sheets
     */
    private function share(DataImport $import, DataImportSheet $confirmed, array $sheets, SavedImportLayout $layout): void
    {
        $shared = 0;

        foreach ($sheets as $sheet) {
            if ($sheet === $confirmed || $sheet->mappingVersion === 0 || $sheet->confirmedByPlayer) {
                continue;
            }

            try {
                $current = ImportMapping::fromArray($sheet->mapping ?? []);
                $options = ImportSheetOptions::fromArray($sheet->options);
            } catch (InvalidImportMapping) {
                continue;
            }

            if (ImportLayoutMatcher::signature($current) !== $layout->signature || ++$shared > self::MAX_SHEETS_SHARED) {
                continue;
            }

            /** @var array<int, array<string, string>> $valueAnswers */
            $valueAnswers = $sheet->valueAnswers ?? [];
            $proposal = $this->matcher->apply(
                $sheet->title,
                // Whatever the sheet got from an earlier confirmation is taken off first: it follows the newest one
                new ImportSheetProposal($current->withQuestions([])->withRemembered(null), $options->withoutPlayerAnswers(), $valueAnswers, $sheet->headerSignature),
                [$layout],
            );
            $mapping = $proposal->mapping;

            if ($mapping->remembered === null) {
                continue;
            }

            // The list other sheets look puzzles up in stays that list
            if ($current->kind === DataImportSheetKind::PuzzleList && $mapping->kind === DataImportSheetKind::Owned) {
                $mapping = $mapping->withKind(DataImportSheetKind::PuzzleList, ColumnCertainty::Certain);
            }

            $sheetId = $sheet->id->toString();
            $table = $mapping->table();
            [$options, $questions] = $this->matcher->settle(
                $mapping,
                $proposal->options,
                $sheet->title,
                fn (): iterable => $this->rows->rows($sheetId, DataImportLimits::COLUMN_DECISION_ROWS, $table->firstRow ?? 1, $table?->lastRow),
                $layout->content,
            );
            $mapping = $mapping->withQuestions($questions);

            if (self::canonical($mapping->toArray()) === self::canonical($sheet->mapping ?? []) && self::canonical($options->toArray()) === self::canonical($sheet->options ?? [])) {
                continue;
            }

            if ($sheet->mappingShared($mapping, $options, $valueAnswers) === false) {
                continue;
            }

            $this->messageBus->dispatch(new ParseImportSheet($import->id->toString(), $sheetId, $sheet->mappingVersion));

            foreach ($sheets as $other) {
                if ($other === $sheet || $other->mappingVersion === 0 || ($other->options['lookupSheetId'] ?? null) !== $sheetId) {
                    continue;
                }

                // As after a confirmed mapping: whoever looks puzzles up in the sheet reads its rows again
                $other->lookedUpSheetChanged();
                $this->messageBus->dispatch(new ParseImportSheet($import->id->toString(), $other->id->toString(), $other->mappingVersion));
            }
        }
    }

    /**
     * The sheet brand and pieces are looked up in, as a layout names it: by its own signature and its title - never
     * by its id, which is this import's.
     *
     * @param list<DataImportSheet> $sheets
     *
     * @return null|array{signature: string, title: string}
     */
    private static function lookupOf(ImportSheetOptions $options, array $sheets): null|array
    {
        foreach ($sheets as $sheet) {
            if ($options->lookupSheetId === null || $sheet->id->toString() !== $options->lookupSheetId) {
                continue;
            }

            try {
                $signature = ImportLayoutMatcher::signature(ImportMapping::fromArray($sheet->mapping ?? []));
            } catch (InvalidImportMapping) {
                return null;
            }

            return $signature === null ? null : ['signature' => $signature, 'title' => mb_substr($sheet->title, 0, 2000)];
        }

        return null;
    }

    private static function nameOf(DataImport $import, DataImportSheet $sheet): string
    {
        $name = trim(trim($import->fileName) . (trim($sheet->title) === '' ? '' : ' - ' . trim($sheet->title)), ' -');

        // '' for a Google sheet nobody named: the list says "Unnamed layout" in the reader's language
        return mb_substr($name, 0, ImportLayout::MAX_NAME_LENGTH);
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
