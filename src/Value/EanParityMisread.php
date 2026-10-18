<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The codes a scanned EAN-13 starting with 0 may have been misread from (docs/features/barcode-scanner/README.md §3.2).
 *
 * An EAN-13's first digit is not printed as bars: it is the pattern of L and G digits in the left half. Android's
 * barcode reader sometimes reads a G digit as the L digit whose bars differ by one module - the pattern becomes
 * all-L, the first digit 0, and the code still passes its check digit (Ravensburger 4005555011897 → 0045555011897,
 * stored as 45555011897). This turns such a read back into every code it could have come from: each first digit 1-9,
 * each G position any digit one module away, the right half as read, the check digit right. Pure maths - only the
 * catalogue decides which of them is real (SearchPuzzle::allByScannedEan()).
 */
final class EanParityMisread
{
    private const array L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
    private const array G = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];
    private const array PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

    /**
     * @return list<string> 13-digit codes, never the read itself; empty for anything that is not an EAN-13/UPC-A
     *     starting with 0 (an EAN-8 is another symbology)
     */
    public static function possibleOriginals(string $scanned): array
    {
        $digits = preg_replace('/\D+/', '', $scanned) ?? '';

        // As the catalogue stores it (45555011897) or as UPC-A (12 digits)
        if (strlen($digits) === 11 || strlen($digits) === 12) {
            $digits = str_pad($digits, 13, '0', STR_PAD_LEFT);
        }

        if (strlen($digits) !== 13 || $digits[0] !== '0' || Ean::tryFrom($digits) === null) {
            return [];
        }

        // Digits a G digit may really have been, for each digit read as L
        $sources = [];
        foreach (self::L as $read => $lPattern) {
            $sources[$read] = [];
            foreach (self::G as $real => $gPattern) {
                if (self::differInOneModule($gPattern, $lPattern)) {
                    $sources[$read][] = (string) $real;
                }
            }
        }

        $originals = [];

        for ($first = 1; $first <= 9; $first++) {
            $leftHalves = [''];

            for ($i = 0; $i < 6; $i++) {
                $read = (int) $digits[$i + 1];
                $choices = self::PARITY[$first][$i] === 'L' ? [(string) $read] : $sources[$read];
                $next = [];

                foreach ($leftHalves as $half) {
                    foreach ($choices as $choice) {
                        $next[] = $half . $choice;
                    }
                }

                $leftHalves = $next;
            }

            foreach ($leftHalves as $half) {
                $candidate = $first . $half . substr($digits, 7);

                if (Ean::tryFrom($candidate) !== null) {
                    $originals[] = $candidate;
                }
            }
        }

        return $originals;
    }

    private static function differInOneModule(string $a, string $b): bool
    {
        $differences = 0;

        for ($i = 0; $i < 7; $i++) {
            if ($a[$i] !== $b[$i]) {
                $differences++;
            }
        }

        return $differences === 1;
    }
}
