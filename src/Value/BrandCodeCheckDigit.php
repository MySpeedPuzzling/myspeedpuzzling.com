<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A brand code with or without the EAN's check digit (docs/features/puzzle-names/codes-help-and-check-digit.md):
 * brands build their EAN from their code - Ravensburger `12002028` ↔ `4005555 02028 8`, `16907` ↔ `4005556 16907 8`,
 * Trefl `37441` ↔ `5900511 37441 4` - and some print the check digit right after the code ("12 002 028 8"), so the
 * code gets typed and stored both ways. The other form of each code is a search alias: a `c:` line of the code key
 * (PuzzleSearchKeys) and a search field of the puzzle picker (PuzzleChoicesBuilder).
 *
 * Only when the digit really is that puzzle's check digit: the EAN must be a valid barcode (catalogue numbers in the
 * EAN field have none) whose body ends with the code's last 5 digits.
 */
readonly final class BrandCodeCheckDigit
{
    // The digits of the code an EAN repeats - Ravensburger's 8-digit codes keep only their last 5 in it
    private const int MATCHED_DIGITS = 5;

    // A longer number in the code field is a barcode, not a brand's article number
    private const int MAX_CODE_DIGITS = 10;

    /**
     * @return list<string> Codes in their search form (SearchText::code()), never one the puzzle already has
     */
    public static function aliases(null|string $storedEans, null|string $storedBrandCodes): array
    {
        $codes = [];

        foreach (BrandCodeList::tokens($storedBrandCodes) as $token) {
            $codes[] = SearchText::code($token);
        }

        $eans = EanList::fromStored($storedEans)->gtins()['gtin13'];

        if ($codes === [] || $eans === []) {
            return [];
        }

        $aliases = [];

        foreach ($codes as $code) {
            $length = strlen($code);

            if (ctype_digit($code) === false || $length < self::MATCHED_DIGITS || $length > self::MAX_CODE_DIGITS) {
                continue;
            }

            foreach ($eans as $ean) {
                $body = substr($ean, 0, -1);
                $checkDigit = substr($ean, -1);

                // Stored without the digit: the code as printed, with it
                if (str_ends_with($body, substr($code, -self::MATCHED_DIGITS))) {
                    $aliases[] = $code . $checkDigit;
                }

                // Stored with the digit: the code itself - still 5+ digits, a shorter `c:` line would match short searches
                $withoutLast = substr($code, 0, -1);

                if (
                    $length > self::MATCHED_DIGITS
                    && str_ends_with($code, $checkDigit)
                    && str_ends_with($body, substr($withoutLast, -self::MATCHED_DIGITS))
                ) {
                    $aliases[] = $withoutLast;
                }
            }
        }

        return array_values(array_diff(array_unique($aliases), $codes));
    }
}
