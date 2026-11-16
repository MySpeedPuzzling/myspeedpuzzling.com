<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportSheetScreen;
use SpeedPuzzling\Web\Results\DataImportSheetOverview;
use SpeedPuzzling\Web\Results\ImportSheetDetail;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * Everything the page of one sheet shows (docs/features/data-import/columns-screen.md) - for the page itself and for
 * the form's refused submit, which shows the same page again with what the player chose. Three statements whatever
 * the sheet's size: the sheet (the caller's), a dozen of its first rows, the first rows as they were understood.
 */
final readonly class ImportSheetPage
{
    public const int PREVIEW_ROWS = 5;

    public function __construct(
        private GetImportSheetScreen $getImportSheetScreen,
        private ImportSheetScreen $screen,
        private GetDataImports $getDataImports,
    ) {
    }

    /**
     * @param null|InputBag<string> $submitted
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    public function parameters(string $importId, ImportSheetDetail $sheet, string $playerId, null|InputBag $submitted = null, array $errors = []): array
    {
        $table = ImportSheetScreen::mappingOf($sheet)?->table();
        $firstCells = $table === null
            ? []
            : $this->getImportSheetScreen->firstCells($importId, $sheet->sheetId, $playerId, $table->firstRow, ImportSheetScreen::SAMPLE_ROWS);

        $view = $this->screen->view($sheet, $firstCells, $submitted, $errors);
        // "Sheet 1 of 2 to check": only read while this sheet asks for the check (one statement, the import's sheets)
        $position = null;
        $held = [];

        if (($view['check_first'] ?? null) !== null) {
            $held = array_values(array_filter(
                $this->getDataImports->sheetsOfPlayer($importId, $playerId),
                static fn (DataImportSheetOverview $other): bool => $other->isHeldForACheck(),
            ));

            foreach ($held as $index => $other) {
                $position = $other->sheetId === $sheet->sheetId ? $index + 1 : $position;
            }
        }

        return [
            ...$view,
            'check_position' => count($held) > 1 ? $position : null,
            'check_total' => count($held),
            'import_id' => $importId,
            'sheet' => $sheet,
            'state' => self::state($sheet),
            'preview' => $sheet->parsed && $sheet->failure === null
                ? $this->getImportSheetScreen->understoodRows($importId, $sheet->sheetId, $playerId, false, self::PREVIEW_ROWS)
                : [],
        ];
    }

    /**
     * The same words the list of sheets has (DataImportSheetOverview::state()).
     */
    public static function state(ImportSheetDetail $sheet): string
    {
        $mapping = ImportSheetScreen::mappingOf($sheet);

        return DataImportSheetOverview::stateOf(
            $sheet->failure !== null,
            $mapping !== null,
            $mapping !== null && $mapping->isImported() === false,
            $sheet->parsed,
            $mapping !== null && $mapping->needsAnAnswer(),
            $sheet->rowsUnderstood,
            $sheet->rowsNeedLook,
            $sheet->rowsWaiting,
        );
    }
}
