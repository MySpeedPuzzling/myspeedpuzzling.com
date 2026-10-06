<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The EAN field of a puzzle: one or more barcodes (one per edition or region), stored as the list
 * `"4005556147090, 4005555001997"` (docs/features/puzzle-names/README.md, "Data model"). The one parser of the stored
 * value and of the form's inputs.
 *
 * Only a barcode is written in its canonical form - digits, leading zeros stripped: a number whose digits as typed are
 * a GTIN (EAN-8, UPC-A, EAN-13, GTIN-14 - or 11 digits, a UPC-A as the catalogue stores it) with a right check digit,
 * and which display() can show as printed again; a dash, a dot or another mark inside it counts as how it was printed
 * only for 12 digits and more ("978-0593137642" - an 8-digit "6000-5533" is a catalogue number). Every other number
 * stays exactly as typed ("6000-5468", "12 556 2", "04512", "4795/4") - its digits may be a catalogue number, a misread
 * or a typo, and only a person can tell. Whatever holds a letter ("X002ROECA7", "None", "N/A") is no number: junk(),
 * kept verbatim too. Everything keeps its place in the list, each code once. Only control and format characters and
 * whitespace around a part go (a stray U+200E), and full-width digits are read as digits.
 *
 * `invalidCodes()` finds the codes in a typed value that are no barcode number, so a form can refuse them. Codes the
 * puzzle already carries are left alone: the catalogue holds legacy values that are not valid codes, and proposing a
 * new photo must not mean fixing those first.
 */
readonly final class EanList
{
    // A form takes at most this many codes per field
    public const int FORM_MAX_CODES = 10;

    // The column's length (varchar 255)
    public const int MAX_STORED_LENGTH = 255;

    // Digits of a GTIN as typed: EAN-8, a UPC-A without its leading zero (how the catalogue stores it), UPC-A, EAN-13,
    // GTIN-14
    private const array GTIN_LENGTHS = [8, 11, 12, 13, 14];

    // Lengths display() shows as printed - an EAN-8 or a UPC-A stored without its leading zero gets it back
    private const array DISPLAYABLE_LENGTHS = [7, 8, 11, 12, 13, 14];

    /**
     * @param list<array{text: string, key: string, kind: 'number'|'typed'|'junk'}> $items As stored, in order: a
     *        number (digits without leading zeros), a number kept as typed, or junk (it holds a letter)
     */
    private function __construct(
        private array $items,
    ) {
    }

    public static function fromStored(null|string $value): self
    {
        // Plain numbers already (toStored() of barcodes) - nothing to parse
        if ($value !== null && preg_match('/^[1-9][0-9]*(?:, [1-9][0-9]*)*$/', $value) === 1) {
            $items = [];

            foreach (explode(', ', $value) as $number) {
                $items['n' . $number] ??= ['text' => $number, 'key' => 'n' . $number, 'kind' => 'number'];
            }

            return new self(array_values($items));
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
        $items = [];

        foreach ($inputs as $input) {
            foreach (self::parts($input ?? '') as $part) {
                foreach (self::itemsOf($part) as $item) {
                    $items[$item['key']] ??= $item;
                }
            }
        }

        return new self(array_values($items));
    }

    /**
     * Every code of both lists, each once, this list's first (a merge: the survivor's codes first).
     */
    public function union(self $other): self
    {
        $items = [];

        foreach ([...$this->items, ...$other->items] as $item) {
            $items[$item['key']] ??= $item;
        }

        return new self(array_values($items));
    }

    /**
     * The same codes in the same order - whatever their stored form.
     */
    public function equals(self $other): bool
    {
        return array_column($this->items, 'key') === array_column($other->items, 'key');
    }

    /**
     * @return list<string> Every number as stored: a barcode as digits without leading zeros, any other as typed
     */
    public function codes(): array
    {
        return array_values(array_map(
            static fn (array $item): string => $item['text'],
            array_filter($this->items, static fn (array $item): bool => $item['kind'] !== 'junk'),
        ));
    }

    /**
     * @return list<string> The values that are no number (they hold a letter), as typed
     */
    public function junk(): array
    {
        return array_values(array_map(
            static fn (array $item): string => $item['text'],
            array_filter($this->items, static fn (array $item): bool => $item['kind'] === 'junk'),
        ));
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * The column value, `", "`-separated - null without any code.
     */
    public function toStored(): null|string
    {
        return $this->isEmpty() ? null : implode(', ', array_column($this->items, 'text'));
    }

    public function fitsColumn(): bool
    {
        return mb_strlen($this->toStored() ?? '') <= self::MAX_STORED_LENGTH;
    }

    /**
     * Each code as it is printed under the barcode: a number stored without the leading zero of its EAN-8 (7 digits)
     * or UPC-A (11 digits) gets it back when the check digit is right; anything else as stored. It is what the forms
     * prefill, so a save keeps every value until somebody changes it.
     *
     * @return list<string>
     */
    public function display(): array
    {
        return array_map(
            static fn (array $item): string => $item['kind'] === 'number' ? self::printed($item['text']) : $item['text'],
            $this->items,
        );
    }

    /**
     * Whether this list (built from $stored) only writes $stored in its canonical form: every part of it stays one code
     * (nothing split - "4005556147090 4005555001997" is no format change), in its place. What changes is how barcodes
     * are written (spaces, dashes, leading zeros inside a GTIN with a right check digit), the separators between
     * parts, what surrounds them and the same code twice; any other number is kept as typed anyway. A comma right
     * between two digits next to a number that is no barcode ("15,427") may be a thousands separator - no format
     * change either. myspeedpuzzling:canonicalize-puzzle-codes writes such changes, a person decides the rest.
     */
    public function isFormatOnlyChangeOf(null|string $stored): bool
    {
        if (self::hasAmbiguousComma($stored)) {
            return false;
        }

        $keys = [];

        foreach (self::parts($stored ?? '') as $part) {
            $items = self::itemsOf($part);

            if (count($items) > 1) {
                return false;
            }

            foreach ($items as $item) {
                $keys[$item['key']] = $item['key'];
            }
        }

        return array_values($keys) === array_column($this->items, 'key');
    }

    /**
     * The parts of a stored value - separated by `,` `;` `|` - as typed, whether they hold a letter, and all their
     * digits as typed (leading zeros kept, full-width digits read as digits).
     *
     * @return list<array{text: string, letters: bool, digits: string}>
     */
    public static function partsOf(null|string $stored): array
    {
        return array_map(
            static fn (string $part): array => [
                'text' => $part,
                'letters' => self::hasLetter($part),
                'digits' => self::digitsOf($part),
            ],
            self::parts($stored ?? ''),
        );
    }

    /**
     * A comma right between two digits ("15,427", "482,239") next to a part that is no barcode - perhaps a thousands
     * separator, perhaps two codes: only a person can tell. Between two barcodes ("4005556147090,4005555001997") it is
     * the list's separator.
     */
    public static function hasAmbiguousComma(null|string $stored): bool
    {
        $pieces = preg_split('/,/u', mb_scrub($stored ?? '', 'UTF-8')) ?: [];

        for ($i = 0; $i < count($pieces) - 1; $i++) {
            $left = self::narrow($pieces[$i]);
            $right = self::narrow($pieces[$i + 1]);

            if (preg_match('/\d$/', $left) !== 1 || preg_match('/^\d/', $right) !== 1) {
                continue;
            }

            foreach ([$pieces[$i], $pieces[$i + 1]] as $piece) {
                $items = self::itemsOf(self::trimmed($piece));

                if (count($items) !== 1 || $items[0]['kind'] !== 'number' || self::isBarcodeAsTyped(self::trimmed($piece)) === false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Digits as typed that are a barcode: a GTIN (EAN-8, UPC-A, EAN-13, GTIN-14, or a UPC-A without its leading zero)
     * with a right check digit, whose canonical form (leading zeros stripped) display() shows as printed again.
     */
    public static function isBarcode(string $digits): bool
    {
        return in_array(strlen($digits), self::GTIN_LENGTHS, true)
            && self::hasValidCheckDigit($digits)
            && in_array(strlen(ltrim($digits, '0')), self::DISPLAYABLE_LENGTHS, true);
    }

    /**
     * A number as typed that is a barcode (isBarcode()): spaces and a `>` after it are how barcodes are printed, any
     * other mark (a dash, a dot) only in one of 12 digits and more - "6000-5533" is a catalogue number, whatever its
     * check digit.
     */
    public static function isBarcodeAsTyped(string $text): bool
    {
        $digits = self::digitsOf($text);

        return self::isBarcode($digits)
            && (strlen($digits) >= 12 || preg_match('/[^0-9\s\p{Z}>]/u', self::narrow($text)) !== 1);
    }

    /**
     * GS1 modulo-10: weights 3, 1, 3, 1… from the right-most digit before the check digit. Leading zeros change
     * nothing, so a code is valid or not whatever it is padded to.
     */
    public static function hasValidCheckDigit(string $digits): bool
    {
        if (strlen($digits) < 2 || preg_match('/^[0-9]+$/', $digits) !== 1) {
            return false;
        }

        $sum = 0;
        $weight = 3;

        for ($i = strlen($digits) - 2; $i >= 0; $i--) {
            $sum += (int) $digits[$i] * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return (10 - ($sum % 10)) % 10 === (int) $digits[strlen($digits) - 1];
    }

    /**
     * The codes of a typed value a form refuses - the one rule of every place a code comes in (forms, internal API,
     * multiscan): a new code is accepted only when this list would store it as a barcode (isBarcodeAsTyped() - so
     * "6000-5533" is refused, a catalogue number whatever its check digit) and Ean takes it. The value is split like
     * a stored one (`,` `;` `|`, then `/`), so pasting several codes into one input is fine. The Ravensburger misread
     * comes back with the full code as its suggestion.
     *
     * @return list<array{code: string, suggestion: null|string}>
     */
    public static function invalidCodes(string $input, null|string $alreadyListed): array
    {
        // Compared code by code: leading zeros, spaces and order do not matter, and a code comes back from a form as
        // it showed it (display())
        $listed = array_flip(array_column(self::fromStored($alreadyListed)->items, 'key'));

        $invalid = [];

        foreach (self::parts($input) as $part) {
            if (self::isListed($part, $listed)) {
                continue;
            }

            $pieces = array_values(array_filter(self::piecesOf($part), static fn (string $piece): bool => ltrim(self::digitsOf($piece), '0') !== ''));

            if (self::hasLetter($part) || $pieces === []) {
                $invalid[] = ['code' => $part, 'suggestion' => null];
                continue;
            }

            foreach ($pieces as $piece) {
                if (self::isListed($piece, $listed)) {
                    continue;
                }

                $suggestion = self::ravensburgerMisreadSuggestion(ltrim(self::digitsOf($piece), '0'));

                if ($suggestion !== null || self::acceptsAsNewCode($piece) === false) {
                    $invalid[] = ['code' => $piece, 'suggestion' => $suggestion];
                }
            }
        }

        return $invalid;
    }

    /**
     * A single code as typed that a form takes as a new one (invalidCodes() finds nothing in it) - the scanners' check.
     */
    public static function isAcceptedNewCode(string $code): bool
    {
        return trim($code) !== '' && self::invalidCodes($code, null) === [];
    }

    /**
     * One number as typed that may be added: stored as a barcode (isBarcodeAsTyped()) and an EAN-8, UPC-A, EAN-13 or a
     * GTIN-14 of one (Ean) - a GTIN-14 with another indicator digit is a carton's code, never a box's.
     */
    private static function acceptsAsNewCode(string $piece): bool
    {
        if (self::isBarcodeAsTyped($piece) === false) {
            return false;
        }

        $digits = self::digitsOf($piece);

        // UPC-A without its leading zero - the form the catalogue stores
        return Ean::tryFrom(strlen($digits) === 11 ? '0' . $digits : $digits) !== null;
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
     * Ravensburger's 4005555…/4005556… typed without its first digit ("005556195145"): the full code when it has a
     * right check digit, or null.
     */
    public static function ravensburgerTruncationSuggestion(string $digits): null|string
    {
        return preg_match('/^00555[56]\d{6}$/', $digits) === 1 && self::hasValidCheckDigit('4' . $digits) ? '4' . $digits : null;
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
     * The violations of a list of inputs (one code per input), each on the input it was typed in: `path[index]` - and
     * one on the field when the codes together do not fit the column (Symfony's own message).
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

        if (self::fromInputs($inputs)->fitsColumn() === false) {
            $context->buildViolation((new Length(max: self::MAX_STORED_LENGTH))->maxMessage)
                ->setParameter('{{ limit }}', (string) self::MAX_STORED_LENGTH)
                ->setPlural(self::MAX_STORED_LENGTH)
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

            foreach (self::splitLongRun($token) as $piece) {
                $number = ltrim(self::digitsOf($piece), '0');

                if ($number !== '') {
                    $numbers[] = $number;
                }
            }
        }

        return ['numbers' => $numbers, 'other' => $other];
    }

    /**
     * The valid barcodes as GTINs for structured data (schema.org `gtin8` / `gtin13`): only numbers whose check digit
     * is right - numbers kept as typed and junk left out. Stored without leading zeros, so they are padded back: a
     * UPC-A becomes its GTIN-13 with a preceding zero, as schema.org's `gtin13` asks for. Only numbers of an EAN-8
     * (8 digits) or an EAN-13 / UPC-A (11-13 digits) length count: the field also holds ISBN-like and catalogue
     * numbers, and padding those would pass every tenth by chance. An EAN-8 starting with 0 is GS1's restricted
     * circulation range - a shop's own code, never a GTIN - so 7 digits are no `gtin8`.
     *
     * @return array{gtin8: list<string>, gtin13: list<string>}
     */
    public function gtins(): array
    {
        $gtins = ['gtin8' => [], 'gtin13' => []];

        foreach ($this->items as $item) {
            [$property, $length] = match ($item['kind'] === 'number' ? strlen($item['text']) : 0) {
                8 => ['gtin8', 8],
                11, 12, 13 => ['gtin13', 13],
                default => [null, 0],
            };

            if ($property === null) {
                continue;
            }

            $ean = Ean::tryFrom(str_pad($item['text'], $length, '0', STR_PAD_LEFT));

            if ($ean !== null && in_array($ean->digits, $gtins[$property], true) === false) {
                $gtins[$property][] = $ean->digits;
            }
        }

        return $gtins;
    }

    /**
     * The items of one part: junk; numbers in their canonical form when every number of the part is a barcode (or the
     * part is a plain number already); otherwise the part as typed, as one item.
     *
     * @return list<array{text: string, key: string, kind: 'number'|'typed'|'junk'}>
     */
    private static function itemsOf(string $part): array
    {
        $kept = ['text' => $part, 'key' => 't' . $part, 'kind' => 'typed'];

        if (self::hasLetter($part)) {
            return [['kind' => 'junk'] + $kept];
        }

        $pieces = array_values(array_filter(self::piecesOf($part), static fn (string $piece): bool => ltrim(self::digitsOf($piece), '0') !== ''));

        if ($pieces === []) {
            return [];
        }

        if (count($pieces) === 1 && ltrim(self::digitsOf($pieces[0]), '0') === $part) {
            return [self::number($part)];
        }

        foreach ($pieces as $piece) {
            if (self::isBarcodeAsTyped($piece) === false) {
                return [$kept];
            }
        }

        return array_map(static fn (string $piece): array => self::number(ltrim(self::digitsOf($piece), '0')), $pieces);
    }

    /**
     * @return array{text: string, key: string, kind: 'number'}
     */
    private static function number(string $digits): array
    {
        return ['text' => $digits, 'key' => 'n' . $digits, 'kind' => 'number'];
    }

    /**
     * The digits of a text as typed - full-width ones read as digits, leading zeros kept.
     */
    private static function digitsOf(string $text): string
    {
        return preg_replace('/[^0-9]+/', '', self::narrow($text)) ?? '';
    }

    /**
     * The numbers of a part as typed: split at `/`, and at spaces within a run of digits too long for one code.
     *
     * @return list<string>
     */
    private static function piecesOf(string $part): array
    {
        $pieces = [];

        foreach (explode('/', $part) as $piece) {
            foreach (self::splitLongRun(trim($piece)) as $number) {
                $pieces[] = $number;
            }
        }

        return $pieces;
    }

    /**
     * A token - or its words, when spaces separate a run of digits too long for one code.
     *
     * @return list<string>
     */
    private static function splitLongRun(string $token): array
    {
        if (strlen(self::digitsOf($token)) > 14 && preg_match('/[\s\p{Z}]/u', $token) === 1) {
            return preg_split('/[\s\p{Z}]+/u', $token) ?: [];
        }

        return [$token];
    }

    /**
     * Every code of the typed value is one the puzzle carries.
     *
     * @param array<string, int> $listed Keys of the listed items
     */
    private static function isListed(string $code, array $listed): bool
    {
        $typed = self::fromStored($code);

        if ($typed->isEmpty()) {
            return false;
        }

        foreach ($typed->items as $item) {
            if (isset($listed[$item['key']]) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * A number as printed: an EAN-8 / UPC-A stored without its leading zero gets it back when its check digit is right.
     */
    private static function printed(string $number): string
    {
        return in_array(strlen($number), [7, 11], true) && self::hasValidCheckDigit($number) ? '0' . $number : $number;
    }

    /**
     * The value split into its parts at `,` `;` `|` (full-width ones too), each trimmed (trimmed()).
     *
     * @return list<string>
     */
    private static function parts(string $value): array
    {
        $parts = [];

        foreach (preg_split('/[,;|\x{FF0C}\x{FF1B}\x{FF5C}]/u', mb_scrub($value, 'UTF-8')) ?: [] as $part) {
            $part = self::trimmed($part);

            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return $parts;
    }

    /**
     * Without whitespace, control and format characters at either end (a stray U+200E) - the rest verbatim.
     */
    private static function trimmed(string $part): string
    {
        return preg_replace('/^[\s\p{Z}\p{Cc}\p{Cf}]+|[\s\p{Z}\p{Cc}\p{Cf}]+$/u', '', $part) ?? '';
    }

    private static function hasLetter(string $value): bool
    {
        return preg_match('/\p{L}/u', $value) === 1;
    }

    /**
     * Full-width ASCII (U+FF01-U+FF5E) and the ideographic space as their ASCII forms - nothing else, "№" stays.
     */
    private static function narrow(string $value): string
    {
        // ASCII is ASCII - nearly every stored value, and the lists are parsed for every option of a picker
        if (preg_match('/[^\x00-\x7F]/', $value) !== 1) {
            return $value;
        }

        return preg_replace_callback(
            '/[\x{FF01}-\x{FF5E}\x{3000}]/u',
            static fn (array $match): string => $match[0] === "\u{3000}" ? ' ' : (string) mb_chr(mb_ord($match[0]) - 0xFEE0),
            mb_scrub($value, 'UTF-8'),
        ) ?? $value;
    }
}
