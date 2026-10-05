<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Normalizer;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The EAN field of a puzzle: one or more barcodes (one per edition or region), stored as the canonical list
 * `"4005556147090, 4005555001997"` - digits, leading zeros stripped, each once, in the order given
 * (docs/features/puzzle-names/README.md, "Data model"). The one parser of the stored value and of the form's inputs.
 *
 * Whatever in the field holds a letter ("X002ROECA7", "None") is no barcode: it is kept apart as junk(), as it was
 * typed, and stored after the codes - a write never loses a stored value, a change proposal removes it.
 *
 * `invalidCodes()` finds the codes in a typed value that are no barcode number, so a form can refuse them. Codes the
 * puzzle already carries are left alone: the catalogue holds legacy values that are not valid codes, and proposing a
 * new photo must not mean fixing those first.
 */
readonly final class EanList
{
    // A form takes at most this many codes per field
    public const int FORM_MAX_CODES = 10;

    /**
     * @param list<string> $codes Digits without leading zeros
     * @param list<string> $junk Values with a letter, as typed
     */
    private function __construct(
        private array $codes,
        private array $junk,
    ) {
    }

    public static function fromStored(null|string $value): self
    {
        // Canonical already (toStored()) - nothing to parse
        if ($value !== null && preg_match('/^[1-9][0-9]*(?:, [1-9][0-9]*)*$/', $value) === 1) {
            return new self(array_values(array_unique(explode(', ', $value))), []);
        }

        return self::fromInputs([$value]);
    }

    /**
     * One entry per input of a form - an entry may still hold several codes (pasted with commas), blank ones are
     * dropped.
     *
     * @param array<null|string> $inputs
     */
    public static function fromInputs(array $inputs): self
    {
        $codes = [];
        $junk = [];

        foreach ($inputs as $input) {
            foreach (self::parts($input ?? '') as $part) {
                if ($part['junk']) {
                    // Keyed with a prefix: a numeric string key would become an integer
                    $junk['k' . self::looseKey($part['text'])] ??= $part['text'];
                    continue;
                }

                foreach ($part['numbers'] as $number) {
                    $codes['k' . $number] = $number;
                }
            }
        }

        return new self(array_values($codes), array_values($junk));
    }

    /**
     * Every barcode number of both lists, each once, this list's first (a merge: the survivor's codes first).
     */
    public function union(self $other): self
    {
        return self::fromInputs([$this->toStored(), $other->toStored()]);
    }

    /**
     * @return list<string> Digits without leading zeros
     */
    public function codes(): array
    {
        return $this->codes;
    }

    /**
     * @return list<string> The values that are no number (they hold a letter), as typed
     */
    public function junk(): array
    {
        return $this->junk;
    }

    public function isEmpty(): bool
    {
        return $this->codes === [] && $this->junk === [];
    }

    /**
     * The column value: the codes, then the junk, `", "`-separated - null without any.
     */
    public function toStored(): null|string
    {
        return $this->isEmpty() ? null : implode(', ', [...$this->codes, ...$this->junk]);
    }

    /**
     * Each code as it is printed under the barcode: a UPC-A (stored as 11 digits without its leading zero) gets its
     * 12th digit back; EAN-13, EAN-8 and anything else stay as stored - a 7-digit number is never padded (an EAN-8
     * starting with 0 is a shop's own code), nor is a number whose check digit fails every way. The junk after the
     * codes, as typed - it is what the forms prefill, so a save keeps it until somebody removes it.
     *
     * @return list<string>
     */
    public function display(): array
    {
        $shown = [];

        foreach ($this->codes as $code) {
            $shown[] = strlen($code) === 11 && Ean::tryFrom('0' . $code) !== null ? '0' . $code : $code;
        }

        return [...$shown, ...$this->junk];
    }

    /**
     * Whether this list (built from $stored) only writes $stored in its canonical form - the same codes in the same
     * order once separators, spaces, leading zeros and case are ignored (each code counted once), nothing dropped,
     * nothing split in two, no junk moved. A number printed with marks other than spaces (a dash, a dot, a `#`)
     * counts only when its digits are a barcode with a right check digit ("978-0593137642"): otherwise it is likely
     * a catalogue number in the wrong field ("6000-5468"), and dropping its dash is no format change.
     * myspeedpuzzling:canonicalize-puzzle-codes writes such changes, a person decides the rest.
     */
    public function isFormatOnlyChangeOf(null|string $stored): bool
    {
        $storedKeys = [];

        foreach (preg_split('/[,;|]/u', self::normalized($stored ?? '')) ?: [] as $token) {
            $key = self::looseKey($token);

            if ($key !== '') {
                $storedKeys['k' . $key] = true;
            }
        }

        $listKeys = [];

        foreach ([...$this->codes, ...$this->junk] as $token) {
            $listKeys['k' . self::looseKey($token)] = true;
        }

        return array_keys($storedKeys) === array_keys($listKeys) && self::catalogueNumbers($stored) === [];
    }

    /**
     * The parts of a stored value holding more than one number ("4005556147090 4005555001997", "4795/4"), as typed -
     * read as several codes, which is no format change.
     *
     * @return list<string>
     */
    public static function partsWithSeveralNumbers(null|string $stored): array
    {
        $parts = [];

        foreach (self::parts($stored ?? '') as $part) {
            if (count($part['numbers']) > 1) {
                $parts[] = $part['text'];
            }
        }

        return $parts;
    }

    /**
     * The parts of a stored value that are a number printed with marks other than spaces (a dash, a dot, a `#`) and
     * no EAN-13 / UPC-A with a right check digit ("6000-5468", "15.427"), as typed - likely a catalogue number in the
     * wrong field. A trailing `>` is how the digits under a barcode end, no mark.
     *
     * @return list<string>
     */
    public static function catalogueNumbers(null|string $stored): array
    {
        $catalogueNumbers = [];

        foreach (self::parts($stored ?? '') as $part) {
            if ($part['junk'] || preg_match('/[^0-9 >]/', $part['text']) !== 1) {
                continue;
            }

            foreach ($part['numbers'] as $number) {
                if (self::isPrintedBarcode($number) === false) {
                    $catalogueNumbers[] = $part['text'];
                    break;
                }
            }
        }

        return $catalogueNumbers;
    }

    /**
     * @return list<array{code: string, suggestion: null|string}>
     */
    public static function invalidCodes(string $input, null|string $alreadyListed): array
    {
        // Compared in their canonical form: leading zeros, spaces and order do not matter, and a stored "#6255" or
        // "4795/4" comes back from a form as the "6255" or "4795" it showed (display())
        $listed = [];
        $listedList = self::fromStored($alreadyListed);
        foreach ([...$listedList->codes, ...$listedList->junk] as $listedCode) {
            $listed['k' . self::looseKey($listedCode)] = true;
        }

        $invalid = [];

        foreach (explode(',', $input) as $part) {
            $code = trim($part);

            if ($code === '' || self::isListed($code, $listed)) {
                continue;
            }

            if (preg_match('/^[\d\s-]+$/', $code) !== 1) {
                $invalid[] = ['code' => $code, 'suggestion' => null];
                continue;
            }

            $digits = preg_replace('/\D+/', '', $code) ?? '';
            $suggestion = self::ravensburgerMisreadSuggestion(ltrim($digits, '0'));

            if ($suggestion !== null) {
                $invalid[] = ['code' => $code, 'suggestion' => $suggestion];
                continue;
            }

            // UPC-A without its leading zero - the form the catalogue stores
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
     * Every code of the typed value is one the puzzle carries (compared in their canonical form).
     *
     * @param array<string, true> $listed
     */
    private static function isListed(string $code, array $listed): bool
    {
        $typed = self::fromStored($code);

        if ($typed->isEmpty()) {
            return false;
        }

        foreach ([...$typed->codes, ...$typed->junk] as $typedCode) {
            if (isset($listed['k' . self::looseKey($typedCode)]) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ravensburger's 4005555…/4005556… read without the two zeros (Android's barcode reader misreads the left half,
     * project notes 2026-10): 23 such codes on prod (2026-10), never another brand. No real code - and dropping two
     * zeros keeps the check digit valid, so only this rule catches it. The full code, or null for any other number.
     */
    public static function ravensburgerMisreadSuggestion(string $digitsWithoutLeadingZeros): null|string
    {
        if (preg_match('/^4555[56]\d{6}$/', $digitsWithoutLeadingZeros) !== 1) {
            return null;
        }

        return '400' . substr($digitsWithoutLeadingZeros, 1);
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
     * The violations of a list of inputs (one code per input), each on the input it was typed in: `path[index]`.
     *
     * @param array<int|string, null|string> $inputs
     */
    public static function addInputViolations(
        ExecutionContextInterface $context,
        string $path,
        array $inputs,
        null|string $alreadyListed,
    ): void {
        foreach ($inputs as $index => $input) {
            self::addViolations($context, sprintf('%s[%s]', $path, $index), $input, $alreadyListed);
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

            foreach (self::numbersOf($token) as $number) {
                $numbers[] = $number;
            }
        }

        return ['numbers' => $numbers, 'other' => $other];
    }

    /**
     * The valid barcodes as GTINs for structured data (schema.org `gtin8` / `gtin13`): only codes whose check digit
     * is right, junk left out. Stored without leading zeros, so they are padded back: a UPC-A becomes its GTIN-13 with
     * a preceding zero, as schema.org's `gtin13` asks for. Only numbers of an EAN-8 (8 digits) or an EAN-13 / UPC-A
     * (11-13 digits) length count: the field also holds ISBN-like and catalogue numbers, and padding those would pass
     * every tenth by chance. An EAN-8 starting with 0 is GS1's restricted circulation range - a shop's own code, never
     * a GTIN - so 7 digits are no `gtin8`.
     *
     * @return array{gtin8: list<string>, gtin13: list<string>}
     */
    public function gtins(): array
    {
        $gtins = ['gtin8' => [], 'gtin13' => []];

        foreach ($this->codes as $number) {
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

    /**
     * The value split into its parts at `,` `;` `|`: a part with a letter is junk (kept whole, "N/A" stays one), the
     * others are numbers, split further at `/` - and at spaces within a run of digits too long for one code.
     *
     * @return list<array{text: string, junk: bool, numbers: list<string>}>
     */
    private static function parts(string $value): array
    {
        $parts = [];

        foreach (preg_split('/[,;|]/u', self::normalized($value)) ?: [] as $part) {
            $part = trim(preg_replace('/[\s\p{Z}]+/u', ' ', $part) ?? '');

            if ($part === '') {
                continue;
            }

            if (preg_match('/\p{L}/u', $part) === 1) {
                $parts[] = ['text' => $part, 'junk' => true, 'numbers' => []];
                continue;
            }

            $numbers = [];

            foreach (explode('/', $part) as $piece) {
                foreach (self::numbersOf(trim($piece)) as $number) {
                    $numbers[] = $number;
                }
            }

            $parts[] = ['text' => $part, 'junk' => false, 'numbers' => $numbers];
        }

        return $parts;
    }

    /**
     * The numbers of one token without letters: its digits without leading zeros - several when spaces separate a
     * run of digits too long for one code.
     *
     * @return list<string>
     */
    private static function numbersOf(string $token): array
    {
        $pieces = [$token];

        if (strlen(preg_replace('/[^0-9]+/', '', $token) ?? '') > 14 && str_contains($token, ' ')) {
            $pieces = explode(' ', $token);
        }

        $numbers = [];

        foreach ($pieces as $piece) {
            $number = ltrim(preg_replace('/[^0-9]+/', '', $piece) ?? '', '0');

            if ($number !== '') {
                $numbers[] = $number;
            }
        }

        return $numbers;
    }

    /**
     * Full-width digits and separators as ASCII (NFKC) - the rest of the text as it is.
     */
    private static function normalized(string $value): string
    {
        // ASCII is its own NFKC - nearly every stored value, and the lists are parsed for every option of a picker
        if (preg_match('/[^\x00-\x7F]/', $value) !== 1) {
            return $value;
        }

        $value = mb_scrub($value, 'UTF-8');
        $normalized = Normalizer::normalize($value, Normalizer::FORM_KC);

        return is_string($normalized) ? $normalized : $value;
    }

    /**
     * A token as compared by isFormatOnlyChangeOf(): letters and digits only, lower case, a number without its
     * leading zeros.
     */
    private static function looseKey(string $token): string
    {
        $key = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', self::normalized($token)) ?? '');

        return preg_match('/^[0-9]+$/', $key) === 1 ? ltrim($key, '0') : $key;
    }

    /**
     * An EAN-13 or a UPC-A (11-13 significant digits) with a right check digit.
     */
    private static function isPrintedBarcode(string $number): bool
    {
        return strlen($number) >= 11
            && strlen($number) <= 13
            && Ean::tryFrom(str_pad($number, 13, '0', STR_PAD_LEFT)) !== null;
    }
}
