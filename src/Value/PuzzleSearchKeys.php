<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The two search keys a puzzle stores (`search_names`, `search_codes`), built by the entity whenever a name or a code
 * changes (docs/features/puzzle-names/README.md, "Search"). One line per name or code, with a newline before the first
 * and after the last, so a whole-line match is a plain `LIKE '%\n…\n%'`.
 */
readonly final class PuzzleSearchKeys
{
    /**
     * Every name folded (SearchText), each once: "\nmain title\nother name\n".
     */
    public static function names(string $name, PuzzleNames $alternatives): string
    {
        $lines = [SearchText::fold($name)];

        foreach ($alternatives->all() as $alternative) {
            $lines[] = SearchText::fold($alternative->name);
        }

        return self::key($lines) ?? "\n";
    }

    /**
     * Every code, each once: `e:` lines are barcode numbers without leading zeros, `c:` lines brand codes (and
     * whatever in the EAN field is no number) as letters and digits only (SearchText::code()), then each brand code
     * with or without the EAN's check digit printed after it (BrandCodeCheckDigit) - so the code is found typed either
     * way. A brand code equal to an EAN is a `c:` line. Null when the puzzle has no code.
     */
    public static function codes(null|string $ean, null|string $identificationNumber): null|string
    {
        $eanTokens = EanList::searchTokens($ean);
        $lines = [];

        foreach ($eanTokens['numbers'] as $number) {
            $lines[] = 'e:' . $number;
        }

        foreach ([...$eanTokens['other'], ...BrandCodeList::tokens($identificationNumber)] as $code) {
            $lines[] = 'c:' . SearchText::code($code);
        }

        foreach (BrandCodeCheckDigit::aliases($ean, $identificationNumber) as $alias) {
            $lines[] = 'c:' . $alias;
        }

        return self::key(array_filter($lines, static fn (string $line): bool => strlen($line) > 2));
    }

    /**
     * @param array<string> $lines
     */
    private static function key(array $lines): null|string
    {
        $lines = array_values(array_unique(array_filter($lines, static fn (string $line): bool => $line !== '')));

        return $lines === [] ? null : "\n" . implode("\n", $lines) . "\n";
    }
}
