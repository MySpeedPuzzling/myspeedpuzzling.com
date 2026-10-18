<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\BoxText;

use SpeedPuzzling\Web\Value\BoxTextCatalogue;
use SpeedPuzzling\Web\Value\BoxTextField;
use SpeedPuzzling\Web\Value\BrandCodeCheckDigit;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\DetectedBoxTextMismatch;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\SearchText;

/**
 * Compares what a box says (the `box_text` analysis, docs/features/insights/image-taxonomy.md §11.2) with the puzzle's
 * record (docs/features/puzzle-approvals.md, "Box-text mismatches"). Per field: a mismatch when the box names values
 * and none of them is the catalogue's - nothing read is no mismatch, an empty catalogue field with a value on the box
 * is one. Codes are compared the way the catalogue stores them: EANs through EanList (leading zeros do not matter),
 * brand codes through BrandCodeList and their search form (a dash or a space does not matter, nor the EAN check digit
 * some brands print after the code - BrandCodeCheckDigit), piece counts as numbers (a multi-pack "2×500" fits 500 and
 * 1000), brand names after trim, collapsed whitespace and lower case. Never a change proposal: moderators decide.
 */
final class BoxTextMismatchDetector
{
    // What box_text keeps at most per list (the pipeline caps them too) - a payload is never trusted
    private const int MAX_VALUES = 10;

    private const int MAX_VALUE_LENGTH = 60;

    /**
     * @param array<mixed> $boxText the `box_text` payload as stored (maps may come back as lists when empty)
     *
     * @return list<DetectedBoxTextMismatch>
     */
    public static function detect(string $puzzleId, array $boxText, BoxTextCatalogue $catalogue): array
    {
        $mismatches = [];

        // Pieces: [{"v": 1000, "evidence": "1000 Teile"}], a multi-pack {"count": 2, "pieces": 500}
        $pieces = [];
        $evidence = [];

        foreach (self::list($boxText['pieces'] ?? null) as $item) {
            if (is_array($item) && is_int($item['v'] ?? null) && $item['v'] > 0 && isset($pieces[$item['v']]) === false) {
                $pieces[$item['v']] = $item['v'];

                if (is_string($item['evidence'] ?? null) && trim($item['evidence']) !== '') {
                    $evidence[] = mb_substr(trim($item['evidence']), 0, self::MAX_VALUE_LENGTH);
                }
            }
        }

        $alsoFits = [];
        $multiPack = $boxText['multiPack'] ?? null;

        if (is_array($multiPack) && is_int($multiPack['count'] ?? null) && is_int($multiPack['pieces'] ?? null)) {
            $alsoFits = [$multiPack['pieces'], $multiPack['count'] * $multiPack['pieces']];
        }

        sort($pieces);

        if ($pieces !== [] && self::piecesMismatch($pieces, $catalogue, $alsoFits)) {
            $mismatches[] = self::mismatch($puzzleId, BoxTextField::Pieces, array_map(strval(...), $pieces), $catalogue, $evidence);
        }

        $eans = self::eanValues(self::strings($boxText['eans'] ?? null));

        if ($eans !== [] && self::stillMismatched(BoxTextField::Ean, $eans, $catalogue)) {
            $mismatches[] = self::mismatch($puzzleId, BoxTextField::Ean, $eans, $catalogue, []);
        }

        $codes = self::brandCodeValues(self::strings($boxText['productCodes'] ?? null));

        if ($codes !== [] && self::stillMismatched(BoxTextField::BrandCode, $codes, $catalogue)) {
            $mismatches[] = self::mismatch($puzzleId, BoxTextField::BrandCode, $codes, $catalogue, []);
        }

        $brands = self::brandValues(self::strings($boxText['brandMentions'] ?? null));

        if ($brands !== [] && self::stillMismatched(BoxTextField::Brand, $brands, $catalogue)) {
            $mismatches[] = self::mismatch($puzzleId, BoxTextField::Brand, $brands, $catalogue, []);
        }

        return $mismatches;
    }

    /**
     * Whether stored box values still differ from the catalogue as it is now - the list re-checks its rows, so a
     * record fixed since the last detection run drops out at once.
     *
     * @param list<string> $boxValues as detect() stores them
     */
    public static function stillMismatched(BoxTextField $field, array $boxValues, BoxTextCatalogue $catalogue): bool
    {
        if ($boxValues === []) {
            return false;
        }

        return match ($field) {
            BoxTextField::Pieces => self::piecesMismatch(array_map(intval(...), $boxValues), $catalogue, []),
            BoxTextField::Ean => array_intersect($boxValues, EanList::fromStored($catalogue->ean)->codes()) === [],
            BoxTextField::BrandCode => self::brandCodesMismatch($boxValues, $catalogue),
            BoxTextField::Brand => self::brandMismatch($boxValues, $catalogue),
        };
    }

    /**
     * @param list<int> $pieces
     * @param list<int> $alsoFits
     */
    private static function piecesMismatch(array $pieces, BoxTextCatalogue $catalogue, array $alsoFits): bool
    {
        return in_array($catalogue->piecesCount, [...$pieces, ...$alsoFits], true) === false;
    }

    /**
     * @param list<string> $boxCodes
     */
    private static function brandCodesMismatch(array $boxCodes, BoxTextCatalogue $catalogue): bool
    {
        $known = array_map(SearchText::code(...), BrandCodeList::fromStored($catalogue->brandCodes)->codes());
        $known = [...$known, ...BrandCodeCheckDigit::aliases($catalogue->ean, $catalogue->brandCodes)];

        foreach ($boxCodes as $code) {
            if (in_array(SearchText::code($code), $known, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $boxBrands
     */
    private static function brandMismatch(array $boxBrands, BoxTextCatalogue $catalogue): bool
    {
        if ($catalogue->brandName === null) {
            return true;
        }

        return in_array(self::normalisedBrand($catalogue->brandName), array_map(self::normalisedBrand(...), $boxBrands), true) === false;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string> barcodes as the catalogue stores them (digits without leading zeros), sorted
     */
    private static function eanValues(array $values): array
    {
        $codes = EanList::fromInputs($values)->codes();
        sort($codes);

        return $codes;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string> codes as the catalogue stores them (upper case), sorted
     */
    private static function brandCodeValues(array $values): array
    {
        $codes = BrandCodeList::fromInputs($values)->codes();
        sort($codes);

        return $codes;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string> each brand once (by its normalised name), sorted
     */
    private static function brandValues(array $values): array
    {
        $brands = [];

        foreach ($values as $value) {
            $brands[self::normalisedBrand($value)] ??= trim((string) preg_replace('/\s+/u', ' ', $value));
        }

        unset($brands['']);
        ksort($brands);

        return array_values($brands);
    }

    private static function normalisedBrand(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * @param list<string> $boxValues
     * @param list<string> $evidence
     */
    private static function mismatch(string $puzzleId, BoxTextField $field, array $boxValues, BoxTextCatalogue $catalogue, array $evidence): DetectedBoxTextMismatch
    {
        return new DetectedBoxTextMismatch(strtolower($puzzleId), $field, $boxValues, $catalogue->valueOf($field), $evidence);
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? array_slice($value, 0, self::MAX_VALUES) : [];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        $strings = [];

        foreach (self::list($value) as $item) {
            if (is_string($item) && trim($item) !== '' && mb_strlen($item) <= self::MAX_VALUE_LENGTH) {
                $strings[] = trim($item);
            }
        }

        return $strings;
    }
}
