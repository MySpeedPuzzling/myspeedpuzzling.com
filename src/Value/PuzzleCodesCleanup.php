<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What myspeedpuzzling:canonicalize-puzzle-codes does with one puzzle's codes (docs/features/puzzle-names/README.md,
 * "Writing names and codes"): both fields are written in their canonical form (EanList / BrandCodeList::toStored())
 * when that only changes how they are written (isFormatOnlyChangeOf()) and keeps the code search key as it is.
 * Anything that would change a value is never written - it becomes a report row, a change proposal for a person.
 */
readonly final class PuzzleCodesCleanup
{
    /**
     * @param list<PuzzleCodesReportRow> $reportRows
     */
    private function __construct(
        // The canonical lists - what a write stores
        public EanList $eans,
        public BrandCodeList $brandCodes,
        // The stored value differs from its canonical form
        public bool $eanChanges,
        public bool $brandCodesChange,
        // Every change is format-only: the puzzle is written (Puzzle::updateProductIdentifiers())
        public bool $writable,
        public array $reportRows,
    ) {
    }

    public static function of(
        string $puzzleId,
        string $puzzleName,
        null|string $ean,
        null|string $identificationNumber,
    ): self {
        $eans = EanList::fromStored($ean);
        $brandCodes = BrandCodeList::fromStored($identificationNumber);
        $eanChanges = $eans->toStored() !== $ean;
        $brandCodesChange = $brandCodes->toStored() !== $identificationNumber;
        $keyBefore = PuzzleSearchKeys::codes($ean, $identificationNumber);

        $row = static fn (string $field, null|string $stored, null|string $proposed, PuzzleCodesCleanupReason $reason, string $detail): PuzzleCodesReportRow
            => new PuzzleCodesReportRow($puzzleId, $puzzleName, $field, $stored, $proposed, $reason, $detail);
        $eanRow = static fn (null|string $proposed, PuzzleCodesCleanupReason $reason, string $detail): PuzzleCodesReportRow
            => $row(PuzzleCodesReportRow::FIELD_EAN, $ean, $proposed, $reason, $detail);

        $rows = [];

        if ($eans->junk() !== []) {
            $rows[] = $eanRow(EanList::fromInputs($eans->codes())->toStored(), PuzzleCodesCleanupReason::EanNotANumber, implode(' | ', $eans->junk()));
        }

        $severalNumbers = EanList::partsWithSeveralNumbers($ean);

        if ($severalNumbers !== []) {
            $rows[] = $eanRow($eans->toStored(), PuzzleCodesCleanupReason::EanSeveralNumbersInOne, implode(' | ', $severalNumbers));
        }

        $catalogueNumbers = EanList::catalogueNumbers($ean);

        if ($catalogueNumbers !== []) {
            $rows[] = $eanRow(
                self::without($eans, EanList::fromInputs($catalogueNumbers)->codes()),
                PuzzleCodesCleanupReason::EanCatalogueNumber,
                implode(' | ', $catalogueNumbers),
            );
        }

        // Each code with the full one in its place where it is a misread - the full code once, when it is listed too
        $corrected = [];
        $misreads = [];

        foreach ($eans->codes() as $code) {
            $suggestion = EanList::ravensburgerMisreadSuggestion($code);
            $corrected[] = $suggestion ?? $code;

            if ($suggestion !== null) {
                $misreads[] = "{$code} → {$suggestion}";
            }
        }

        if ($misreads !== []) {
            $rows[] = $eanRow(
                EanList::fromInputs([...$corrected, ...$eans->junk()])->toStored(),
                PuzzleCodesCleanupReason::EanRavensburgerMisread,
                implode(' | ', $misreads),
            );
        }

        $eanFormatOnly = $eans->isFormatOnlyChangeOf($ean);

        if ($eanChanges && $eanFormatOnly === false && $severalNumbers === [] && $catalogueNumbers === [] && $eans->junk() === []) {
            $rows[] = $eanRow($eans->toStored(), PuzzleCodesCleanupReason::EanNotFormatOnly, '');
        }

        $storedBrandCodes = BrandCodeList::tokens($identificationNumber);
        $notCodes = array_values(array_filter($storedBrandCodes, self::isNotABrandCode(...)));

        if ($notCodes !== []) {
            $rows[] = $row(
                PuzzleCodesReportRow::FIELD_BRAND_CODES,
                $identificationNumber,
                BrandCodeList::fromInputs(array_diff($storedBrandCodes, $notCodes))->toStored(),
                PuzzleCodesCleanupReason::BrandCodeNotACode,
                implode(' | ', $notCodes),
            );
        }

        $eanKeyKept = PuzzleSearchKeys::codes($eans->toStored(), $identificationNumber) === $keyBefore;
        $brandCodesKeyKept = PuzzleSearchKeys::codes($ean, $brandCodes->toStored()) === $keyBefore;

        if ($eanChanges && $eanFormatOnly && $eanKeyKept === false) {
            $rows[] = $eanRow($eans->toStored(), PuzzleCodesCleanupReason::SearchKeyWouldChange, (string) $keyBefore);
        }

        $brandCodesFormatOnly = $brandCodes->isFormatOnlyChangeOf($identificationNumber);

        if ($brandCodesChange && ($brandCodesFormatOnly === false || $brandCodesKeyKept === false)) {
            $rows[] = $row(
                PuzzleCodesReportRow::FIELD_BRAND_CODES,
                $identificationNumber,
                $brandCodes->toStored(),
                PuzzleCodesCleanupReason::SearchKeyWouldChange,
                (string) $keyBefore,
            );
        }

        // Words in the brand codes stay as typed until a person removes them: in upper case they no longer read as words
        $writable = ($eanChanges || $brandCodesChange)
            && ($eanChanges === false || ($eanFormatOnly && $eanKeyKept))
            && ($brandCodesChange === false || ($brandCodesFormatOnly && $brandCodesKeyKept && $notCodes === []))
            && PuzzleSearchKeys::codes($eans->toStored(), $brandCodes->toStored()) === $keyBefore;

        return new self($eans, $brandCodes, $eanChanges, $brandCodesChange, $writable, $rows);
    }

    /**
     * A brand code as typed that is a word, words or a placeholder - no digit, and either a space, a capitalised or
     * lower-case word ("Clementoni", "Alpine village", "not available") or "N/A", "NA", "None", "Unknown". Codes of
     * capital letters only ("PZFSLF", "PZL/USA") are real codes of some brands.
     */
    private static function isNotABrandCode(string $code): bool
    {
        if (preg_match('/\p{N}/u', $code) === 1) {
            return false;
        }

        return preg_match('/\s/u', $code) === 1
            || preg_match('/^(\p{Lu}\p{Ll}+|\p{Ll}+)$/u', $code) === 1
            || preg_match('/^(N\/?A|NONE|UNKNOWN|O)$/i', $code) === 1;
    }

    /**
     * @param list<string> $codes
     */
    private static function without(EanList $eans, array $codes): null|string
    {
        return EanList::fromInputs([...array_diff($eans->codes(), $codes), ...$eans->junk()])->toStored();
    }
}
