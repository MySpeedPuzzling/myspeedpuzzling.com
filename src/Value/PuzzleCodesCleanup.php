<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What myspeedpuzzling:canonicalize-puzzle-codes does with one puzzle's codes (docs/features/puzzle-names/README.md,
 * "Writing names and codes"). A field is written in its canonical form (Puzzle::canonicalizeProductIdentifiers()) only
 * when that changes how it is written and nothing else (EanList / BrandCodeList::isFormatOnlyChangeOf() - a barcode's
 * spaces and leading zeros, separators, case) and keeps the code search key byte for byte. Any other number stays as
 * typed. What a person decides becomes report rows, which all carry one proposal for the puzzle - a part of the EAN
 * field that is a catalogue number moves into the brand codes, never vanishes.
 */
readonly final class PuzzleCodesCleanup
{
    // A placeholder typed for "no code" - a word, never a code
    private const array PLACEHOLDERS = ['NA', 'NONE', 'UNKNOWN', 'O'];

    /**
     * @param list<PuzzleCodesReportRow> $reportRows
     */
    private function __construct(
        // Write the field in its canonical form - a format-only change
        public bool $writeEans,
        public bool $writeBrandCodes,
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
        $keyBefore = PuzzleSearchKeys::codes($ean, $identificationNumber);

        /** @var array<string, array{field: string, reason: PuzzleCodesCleanupReason, parts: list<string>}> $issues */
        $issues = [];
        $issue = static function (string $field, PuzzleCodesCleanupReason $reason, string $part) use (&$issues): void {
            $issues[$reason->value] ??= ['field' => $field, 'reason' => $reason, 'parts' => []];
            $issues[$reason->value]['parts'][] = $part;
        };

        // The EAN field as proposed, part by part, and what moves from it into the brand codes
        $proposedEans = [];
        $moved = [];

        foreach (EanList::partsOf($ean) as $part) {
            $text = $part['text'];

            if ($part['letters']) {
                $issue(PuzzleCodesReportRow::FIELD_EAN, PuzzleCodesCleanupReason::EanNotANumber, $text);
                $moved[] = $text;
                continue;
            }

            $numbers = array_values(array_filter($part['digits'], static fn (string $digits): bool => ltrim($digits, '0') !== ''));

            if ($numbers === []) {
                continue;
            }

            if (count($numbers) > 1) {
                if (array_filter($numbers, EanList::isBarcode(...)) === $numbers) {
                    $issue(PuzzleCodesReportRow::FIELD_EAN, PuzzleCodesCleanupReason::EanSeveralNumbersInOne, $text);
                    array_push($proposedEans, ...$numbers);
                } else {
                    $issue(PuzzleCodesReportRow::FIELD_EAN, PuzzleCodesCleanupReason::EanCatalogueNumber, $text);
                    $moved[] = $text;
                }

                continue;
            }

            $digits = $numbers[0];
            $number = ltrim($digits, '0');
            $misread = EanList::ravensburgerMisreadSuggestion($number);

            if ($misread !== null) {
                $issue(PuzzleCodesReportRow::FIELD_EAN, PuzzleCodesCleanupReason::EanRavensburgerMisread, "{$text} → {$misread}");
                $proposedEans[] = $misread;
                continue;
            }

            if ($number === $text || EanList::isBarcode($digits)) {
                $proposedEans[] = $text;
                continue;
            }

            if (preg_match('/[^0-9\s\p{Z}>]/u', $text) === 1) {
                $issue(PuzzleCodesReportRow::FIELD_EAN, PuzzleCodesCleanupReason::EanCatalogueNumber, $text);
                $moved[] = $text;
                continue;
            }

            $truncated = EanList::ravensburgerTruncationSuggestion($digits);

            if ($truncated !== null) {
                $issue(PuzzleCodesReportRow::FIELD_EAN, PuzzleCodesCleanupReason::EanNotABarcode, "{$text} → {$truncated}");
                $proposedEans[] = $truncated;
            } elseif (in_array(strlen($digits), [8, 11, 12, 13, 14], true)) {
                // A barcode's length with a wrong check digit - the box tells which digit
                $issue(PuzzleCodesReportRow::FIELD_EAN, PuzzleCodesCleanupReason::EanNotABarcode, "{$text} (check digit)");
                $proposedEans[] = $text;
            } else {
                $issue(PuzzleCodesReportRow::FIELD_EAN, PuzzleCodesCleanupReason::EanNotABarcode, $text);
                $moved[] = $text;
            }
        }

        // The brand codes as proposed: words and placeholders out, codes with words in them kept for a person, then
        // whatever the EAN field holds that is a code - each once, the existing ones first
        $proposedBrandCodes = [];
        $brandCodesForAPerson = false;

        foreach (BrandCodeList::tokens($identificationNumber) as $token) {
            if (self::isNotABrandCode($token)) {
                $issue(PuzzleCodesReportRow::FIELD_BRAND_CODES, PuzzleCodesCleanupReason::BrandCodeNotACode, $token);
                $brandCodesForAPerson = true;
                continue;
            }

            if (self::isProse($token)) {
                $issue(PuzzleCodesReportRow::FIELD_BRAND_CODES, PuzzleCodesCleanupReason::BrandCodeProse, $token);
                $brandCodesForAPerson = true;
            }

            $proposedBrandCodes[] = $token;
        }

        foreach ($moved as $part) {
            if (self::isNotABrandCode($part) === false) {
                $proposedBrandCodes[] = $part;
            }
        }

        $proposedEan = EanList::fromInputs($proposedEans)->toStored();
        $proposedBrandCode = BrandCodeList::fromInputs($proposedBrandCodes)->toStored();

        // Format-only, and the search key stays: what the command writes
        $eanChanges = $eans->toStored() !== $ean;
        $brandCodesChange = $brandCodes->toStored() !== $identificationNumber;
        $writeEans = $eanChanges
            && $eans->isFormatOnlyChangeOf($ean)
            && PuzzleSearchKeys::codes($eans->toStored(), $identificationNumber) === $keyBefore;
        $writeBrandCodes = $brandCodesChange
            && $brandCodesForAPerson === false
            && $brandCodes->isFormatOnlyChangeOf($identificationNumber)
            && PuzzleSearchKeys::codes($ean, $brandCodes->toStored()) === $keyBefore;

        if ($writeEans && $writeBrandCodes && PuzzleSearchKeys::codes($eans->toStored(), $brandCodes->toStored()) !== $keyBefore) {
            $writeEans = false;
            $writeBrandCodes = false;
        }

        $eanIssues = array_filter($issues, static fn (array $found): bool => $found['field'] === PuzzleCodesReportRow::FIELD_EAN);

        if ($eanChanges && $writeEans === false && $eanIssues === []) {
            $reason = $eans->isFormatOnlyChangeOf($ean) ? PuzzleCodesCleanupReason::SearchKeyWouldChange : PuzzleCodesCleanupReason::EanNotFormatOnly;
            $issue(PuzzleCodesReportRow::FIELD_EAN, $reason, (string) $ean);
        }

        if ($brandCodesChange && $writeBrandCodes === false && $brandCodesForAPerson === false) {
            $issue(PuzzleCodesReportRow::FIELD_BRAND_CODES, PuzzleCodesCleanupReason::SearchKeyWouldChange, (string) $identificationNumber);
        }

        $rows = [];

        foreach ($issues as $found) {
            $isEan = $found['field'] === PuzzleCodesReportRow::FIELD_EAN;

            $rows[] = new PuzzleCodesReportRow(
                puzzleId: $puzzleId,
                puzzleName: $puzzleName,
                field: $found['field'],
                stored: $isEan ? $ean : $identificationNumber,
                proposed: $isEan ? $proposedEan : $proposedBrandCode,
                reason: $found['reason'],
                detail: implode(' | ', $found['parts']),
                currentBrandCodes: $identificationNumber,
                proposedBrandCodes: $proposedBrandCode,
            );
        }

        return new self($writeEans, $writeBrandCodes, $rows);
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
            || in_array(mb_strtoupper(preg_replace('/[^\p{L}\p{N}]+/u', '', $code) ?? ''), self::PLACEHOLDERS, true);
    }

    /**
     * A brand code with a word in it: three letters at least standing apart from digits, one of them lower case
     * ("Article 30226", "lot number"), or a lower-case word between spaces ("UPC is 0045622965214"). Codes typed in
     * lower case like "rb-500-001", "Jk009", "1183pz" or "m051524b" are no words.
     */
    private static function isProse(string $code): bool
    {
        return preg_match('/(?<![\p{L}\p{N}])(?=\p{L}*\p{Ll})\p{L}{3,}(?![\p{L}\p{N}])/u', $code) === 1
            || preg_match('/\s\p{Ll}{2,}\s/u', $code) === 1;
    }
}
