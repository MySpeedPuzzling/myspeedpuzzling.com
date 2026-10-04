<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The EAN field of a puzzle: one or more codes, comma-separated (one per edition or region).
 *
 * `invalidCodes()` finds the codes in a typed list that are no barcode number, so a form can
 * refuse them. Codes the puzzle already carries are left alone: the catalogue holds legacy values
 * that are not valid codes, and proposing a new photo must not mean fixing those first.
 */
readonly final class EanList
{
    /**
     * @return list<array{code: string, suggestion: null|string}>
     */
    public static function invalidCodes(string $input, null|string $alreadyListed): array
    {
        $listed = [];
        foreach (explode(',', $alreadyListed ?? '') as $part) {
            $listed[self::key(trim($part))] = true;
        }

        $invalid = [];

        foreach (explode(',', $input) as $part) {
            $code = trim($part);

            if ($code === '' || isset($listed[self::key($code)])) {
                continue;
            }

            if (preg_match('/^[\d\s-]+$/', $code) !== 1) {
                $invalid[] = ['code' => $code, 'suggestion' => null];
                continue;
            }

            $digits = preg_replace('/\D+/', '', $code) ?? '';
            $significant = ltrim($digits, '0');

            // Ravensburger's 4005555…/4005556… typed without the two zeros: 23 such codes on prod
            // (2026-10), never another brand. No real code - and dropping two zeros keeps the
            // check digit valid, so only this rule catches it.
            if (preg_match('/^4555[56]\d{6}$/', $significant) === 1) {
                $invalid[] = ['code' => $code, 'suggestion' => '400' . substr($significant, 1)];
                continue;
            }

            // UPC-A without its leading zero - the form the catalogue stores and shows
            if (strlen($digits) === 11) {
                $digits = '0' . $digits;
            }

            if (Ean::tryFrom($digits) === null) {
                $invalid[] = ['code' => $code, 'suggestion' => null];
            }
        }

        return $invalid;
    }

    private static function key(string $code): string
    {
        if (preg_match('/^[\d\s-]+$/', $code) !== 1) {
            return $code;
        }

        return ltrim(preg_replace('/\D+/', '', $code) ?? '', '0');
    }
}
