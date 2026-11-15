<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportSheet;
use SpeedPuzzling\Web\Entity\ImportLayout;
use SpeedPuzzling\Web\Exceptions\InvalidImportMapping;
use SpeedPuzzling\Web\Message\ShareImportLayout;
use SpeedPuzzling\Web\Repository\ImportLayoutRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetOptions;
use SpeedPuzzling\Web\Value\DataImport\SavedImportLayout;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Remembers what a player confirmed for a sheet (docs/features/data-import/layouts.md) - called by
 * ConfirmImportMappingHandler once the sheet's new mapping stands, inside its transaction and under the import's
 * lock:
 *
 * 1. the sheet's layout is saved for the player - a new one, or the one of the same signature with the newer word;
 * 2. the other sheets of the same workbook the layout fits that the player has not confirmed themselves are asked
 *    to follow (ShareImportLayout, on the imports queue). A sheet the player confirmed is never touched.
 *
 * A sheet without a header row, and one the player left out, is not remembered.
 */
final readonly class ImportLayouts
{
    /** A player's saved layouts: one more replaces the one used longest ago */
    public const int MAX_PER_PLAYER = 50;
    /** Sheets of one workbook that follow a confirmed one in a single step */
    public const int MAX_SHEETS_SHARED = 30;

    private const string LOCK_NAME = 'import_layout';

    public function __construct(
        private ImportLayoutRepository $layoutRepository,
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
        $content = ImportLayoutMatcher::contentOf($mapping, $options, self::lookupOf($options, $sheets), $sheet->title);

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

        $this->askSiblingsToFollow($import, $sheet, $sheets, new SavedImportLayout(
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
     * Within one workbook: the sheets the layout fits (the same header row, or most of it) that the player has not
     * confirmed themselves are to follow the confirmed one. Here they only get a new version - so the screen shows
     * them as being worked out, and no older job writes on - and one ShareImportLayout is queued: working a sheet out
     * reads thousands of its rows, which is a worker's job and not this request's.
     *
     * @param list<DataImportSheet> $sheets
     */
    private function askSiblingsToFollow(DataImport $import, DataImportSheet $confirmed, array $sheets, SavedImportLayout $layout): void
    {
        $asked = 0;

        foreach ($sheets as $sheet) {
            if ($sheet === $confirmed || $sheet->mappingVersion === 0 || $sheet->confirmedByPlayer || $asked >= self::MAX_SHEETS_SHARED) {
                continue;
            }

            try {
                $current = ImportMapping::fromArray($sheet->mapping ?? []);
            } catch (InvalidImportMapping) {
                continue;
            }

            if (ImportLayoutMatcher::matching($current, [$layout]) === null) {
                continue;
            }

            $sheet->followsAConfirmedSheet();
            $asked++;
        }

        if ($asked > 0) {
            $this->messageBus->dispatch(new ShareImportLayout($import->id->toString(), $layout->id));
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
}
