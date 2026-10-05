<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Symfony\Component\Validator\Context\ExecutionContextInterface;

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

    /**
     * One violation per invalid code, on the given field (messages in the validators domain).
     */
    public static function addViolations(
        ExecutionContextInterface $context,
        string $path,
        null|string $input,
        null|string $alreadyListed,
    ): void {
        foreach (self::invalidCodes($input ?? '', $alreadyListed) as $invalid) {
            $violation = $invalid['suggestion'] === null
                ? $context->buildViolation('ean_invalid')
                : $context->buildViolation('ean_missing_zeros')->setParameter('%suggestion%', $invalid['suggestion']);

            $violation
                ->setParameter('%code%', $invalid['code'])
                ->atPath($path)
                ->addViolation();
        }
    }

    /**
     * The codes of a stored EAN value for the search key (PuzzleSearchKeys): every barcode number as digits without
     * leading zeros, and apart from them, folded, every token with a letter in it (junk like "X002ROECA7" or "None").
     *
     * The value is folded first (SearchText): full-width digits and separators become ASCII, every whitespace run one
     * space - so only ASCII digits are left to look at. Codes are separated by `,` `;` `/` `|`. Whatever else a number
     * holds is how it was printed (spaces, dashes, dots, the `>` after the digits under a barcode) - except in a run of
     * digits too long for one code, where spaces separate several.
     *
     * @return array{numbers: list<string>, other: list<string>}
     */
    public static function searchTokens(null|string $value): array
    {
        $numbers = [];
        $other = [];

        foreach (preg_split('/[,;\/|]/', SearchText::fold($value ?? '')) ?: [] as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            if (preg_match('/\p{L}/u', $token) === 1) {
                $other[] = $token;
                continue;
            }

            $parts = [$token];

            if (strlen(preg_replace('/[^0-9]+/', '', $token) ?? '') > 14 && str_contains($token, ' ')) {
                $parts = explode(' ', $token);
            }

            foreach ($parts as $part) {
                $number = ltrim(preg_replace('/[^0-9]+/', '', $part) ?? '', '0');

                if ($number !== '') {
                    $numbers[] = $number;
                }
            }
        }

        return ['numbers' => $numbers, 'other' => $other];
    }

    /**
     * The valid barcodes of a stored EAN value as GTINs for structured data (schema.org `gtin8` / `gtin13`): only
     * codes whose check digit is right, junk and brand codes left out. Stored without leading zeros, so they are
     * padded back: a UPC-A becomes its GTIN-13 with a preceding zero, as schema.org's `gtin13` asks for. Only numbers
     * of an EAN-8 (8 digits) or an EAN-13 / UPC-A (11-13 digits) length count: the field also holds ISBN-like and
     * catalogue numbers, and padding those would pass every tenth by chance. An EAN-8 starting with 0 is GS1's
     * restricted circulation range - a shop's own code, never a GTIN - so 7 digits are no `gtin8`.
     *
     * @return array{gtin8: list<string>, gtin13: list<string>}
     */
    public static function gtins(null|string $value): array
    {
        $gtins = ['gtin8' => [], 'gtin13' => []];

        foreach (self::searchTokens($value)['numbers'] as $number) {
            [$property, $length] = match (strlen($number)) {
                8 => ['gtin8', 8],
                11, 12, 13 => ['gtin13', 13],
                default => [null, 0],
            };

            if ($property === null) {
                continue;
            }

            $ean = Ean::tryFrom(str_pad($number, $length, '0', STR_PAD_LEFT));

            if ($ean !== null && in_array($ean->digits, $gtins[$property], true) === false) {
                $gtins[$property][] = $ean->digits;
            }
        }

        return $gtins;
    }

    private static function key(string $code): string
    {
        if (preg_match('/^[\d\s-]+$/', $code) !== 1) {
            return $code;
        }

        return ltrim(preg_replace('/\D+/', '', $code) ?? '', '0');
    }
}
