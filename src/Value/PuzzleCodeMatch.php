<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The code a typed search found a puzzle by (docs/features/puzzle-names/README.md "Display": "Matched: …" in search
 * results) - for a result list that shows no codes, so a row found by its EAN or brand code says why it is there
 * (a player's Results, docs/features/navigation PROF review Q3).
 *
 * The same rules as the stored search key (PuzzleSearchKeys::codes()) and the patterns PuzzleTextSearch matches it with
 * (PuzzleSearchQuery): a whole barcode (the typed number without leading zeros), a whole brand code (its letters and
 * digits, the EAN's check digit after it or not - BrandCodeCheckDigit) or a part of any code from 5 letters/digits on.
 * Nothing when one of the puzzle's names holds the text: the name lines say it (puzzle/_name.html.twig).
 */
readonly final class PuzzleCodeMatch
{
    /**
     * @return null|string the code as printed (EanList / BrandCodeList display()), null when no code matched or a name did
     */
    public static function find(
        null|string $search,
        string $mainTitle,
        PuzzleNames $names,
        null|string $storedEans,
        null|string $storedBrandCodes,
    ): null|string {
        $query = PuzzleSearchQuery::fromUserInput($search);

        if ($query->isEmpty() || str_contains(PuzzleSearchKeys::names($mainTitle, $names), $query->folded)) {
            return null;
        }

        // PuzzleSearchQuery::fromUserInput()'s code patterns, as plain values
        $isNumber = preg_match('/^[0-9 .\-]+$/', $query->folded) === 1;
        $digits = $isNumber ? ltrim(preg_replace('/[^0-9]+/', '', $query->folded) ?? '', '0') : '';
        $alnum = preg_replace('/[^\p{L}\p{N}]+/u', '', $query->folded) ?? '';
        $part = $isNumber ? $digits : $alnum;
        $part = mb_strlen($part) >= PuzzleSearchQuery::MIN_CODE_PART_LENGTH ? $part : null;

        $matches = static function (string $tag, string $code) use ($digits, $alnum, $part): bool {
            if ($code === '') {
                return false;
            }

            return ($tag === 'e' && $digits !== '' && $code === $digits)
                || ($tag === 'c' && $alnum !== '' && $code === $alnum)
                || ($part !== null && str_contains($code, $part));
        };

        foreach (EanList::fromStored($storedEans)->display() as $printed) {
            $tokens = EanList::searchTokens($printed);

            foreach ($tokens['numbers'] as $number) {
                if ($matches('e', $number)) {
                    return $printed;
                }
            }

            foreach ($tokens['other'] as $other) {
                if ($matches('c', SearchText::code($other))) {
                    return $printed;
                }
            }
        }

        foreach (BrandCodeList::fromStored($storedBrandCodes)->display() as $printed) {
            if ($matches('c', SearchText::code($printed))) {
                return $printed;
            }

            foreach (BrandCodeCheckDigit::aliases($storedEans, $printed) as $alias) {
                if ($matches('c', $alias)) {
                    return $printed;
                }
            }
        }

        return null;
    }
}
