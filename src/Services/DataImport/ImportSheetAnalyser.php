<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Services\DataImport\Reading\HeaderMatcher;
use SpeedPuzzling\Web\Value\DataImport\ColumnCertainty;
use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetOptions;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetProposal;
use SpeedPuzzling\Web\Value\DataImport\ImportWordMeaning;
use SpeedPuzzling\Web\Value\DataImport\KnownExportLayout;
use SpeedPuzzling\Web\Value\DataImport\SheetSample;
use SpeedPuzzling\Web\Value\DataImportRowMeaning;
use SpeedPuzzling\Web\Value\DataImportSheetKind;

/**
 * Proposes a sheet's mapping from a sample of its rows (docs/features/data-import/columns.md): where its table is
 * (DataRegionFinder), whether it is a known export (KnownExportLayout), what each column is (ColumnRecogniser), what
 * the sheet holds and what a row of it is (SheetKindGuesser). Pure but for one read: the brands of the catalogue,
 * once per sheet and only when a column could be the brand by its values (ColumnRecogniser::withBrandByValues()) -
 * without a resolver (a test of the pure parts) the brand is never guessed from values.
 *
 * What it does not do: settle the open readings of times and dates (ImportColumnDecisions, over more rows than a
 * sample) and see that a sheet is the puzzle list other sheets point to (SheetLookup, across the workbook).
 */
final readonly class ImportSheetAnalyser
{
    public function __construct(
        private DataRegionFinder $regionFinder,
        private ColumnRecogniser $columnRecogniser,
        private SheetKindGuesser $kindGuesser,
        private null|ImportBrandResolver $brandResolver = null,
    ) {
    }

    /**
     * @param null|callable(list<string>): array<string, string> $brandsOf which of some texts are brands of the
     *        catalogue, by the text as given - null: the catalogue's own answer (ImportBrandResolver)
     */
    public function analyse(SheetSample $sample, null|callable $brandsOf = null): ImportSheetProposal
    {
        $brandsOf ??= $this->brandResolver === null ? null : $this->brandResolver->resolveApproved(...);

        $regions = $this->regionFinder->find($sample);

        if ($regions === []) {
            return new ImportSheetProposal(
                new ImportMapping(DataImportSheetKind::NotPuzzles, ColumnCertainty::Certain, DataImportRowMeaning::Result, [], 0, []),
                new ImportSheetOptions(),
            );
        }

        $table = $regions[0];
        $headers = DataRegionFinder::headers($sample, $table);
        $layout = $table->headerRow === null ? null : KnownExportLayout::matching($headers);
        $rows = array_filter($sample->rows, static fn (int $number): bool => $table->holdsRow($number), ARRAY_FILTER_USE_KEY);
        $columns = $this->columnRecogniser->recognise($table, $headers, $rows, $layout);

        if ($layout === null && $brandsOf !== null) {
            $columns = ColumnRecogniser::withBrandByValues($columns, $rows, $brandsOf);
        }
        [$kind, $certainty, $rowMeaning] = $this->kindGuesser->guess($sample->title, $columns, $layout);

        $mapping = new ImportMapping($kind, $certainty, $rowMeaning, $regions, 0, $columns, [], $layout?->id);
        $options = new ImportSheetOptions();
        $valueAnswers = [];

        if ($layout !== null) {
            if ($layout->dateOrder !== null) {
                $options = $options->withDateOrder($layout->dateOrder, ImportSheetOptions::BY_KNOWN_EXPORT);
            }

            $status = $mapping->column(ImportField::Status);

            if ($status !== null && $layout->statusWords !== []) {
                $valueAnswers[$status->column] = array_map(static fn (ImportWordMeaning $meaning): string => $meaning->value, $layout->statusWords);
            }
        }

        return new ImportSheetProposal($mapping, $options, $valueAnswers, self::signature($headers));
    }

    /**
     * What two sheets with the same headers share: `1:` + the sha256 of the headers as they are compared
     * (HeaderMatcher::words()), left to right - null for a table without any header.
     *
     * @param array<int, string> $headers
     */
    public static function signature(array $headers): null|string
    {
        $words = array_map(HeaderMatcher::words(...), array_values($headers));

        return array_filter($words) === [] ? null : '1:' . hash('sha256', implode("\n", $words));
    }
}
