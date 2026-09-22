<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Exceptions\InvalidEan;

/**
 * A GS1 product code as read from a puzzle box (EAN-13, EAN-8, or UPC-A which
 * the scanner reports as EAN-13 with a leading zero).
 *
 * Multiscan runs every code through this one place so that the lookup, the
 * link and the create all agree: what is stored is what is later found.
 * `normalized()` strips leading zeros exactly like AddPuzzleHandler stores them.
 */
readonly final class Ean
{
    private function __construct(
        public string $digits,
    ) {
    }

    public static function tryFrom(string $input): null|self
    {
        $digits = preg_replace('/\D+/', '', $input) ?? '';

        if (strlen($digits) === 12) {
            // UPC-A is an EAN-13 with a leading zero
            $digits = '0' . $digits;
        } elseif (strlen($digits) === 14 && $digits[0] === '0') {
            // GTIN-14 with a zero indicator digit is the same article as the EAN-13 inside it
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 13 && strlen($digits) !== 8) {
            return null;
        }

        if (self::checkDigit(substr($digits, 0, -1)) !== (int) $digits[strlen($digits) - 1]) {
            return null;
        }

        if (ltrim($digits, '0') === '') {
            return null;
        }

        return new self($digits);
    }

    /**
     * @throws InvalidEan
     */
    public static function from(string $input): self
    {
        return self::tryFrom($input) ?? throw new InvalidEan();
    }

    /**
     * Storage form: no leading zeros (the convention of AddPuzzleHandler and the LIKE lookups).
     */
    public function normalized(): string
    {
        return ltrim($this->digits, '0');
    }

    public function equals(self $other): bool
    {
        return $this->normalized() === $other->normalized();
    }

    /**
     * Whether a stored puzzle.ean value (possibly a comma-separated list of codes,
     * possibly with leading zeros) carries this code.
     */
    public function isListedIn(null|string $storedEan): bool
    {
        if ($storedEan === null) {
            return false;
        }

        foreach (explode(',', $storedEan) as $part) {
            if (ltrim(trim($part), '0') === $this->normalized()) {
                return true;
            }
        }

        return false;
    }

    /**
     * GS1 modulo-10: weights 3,1,3,1… applied from the right-most data digit.
     */
    private static function checkDigit(string $data): int
    {
        $sum = 0;
        $weight = 3;

        for ($i = strlen($data) - 1; $i >= 0; $i--) {
            $sum += (int) $data[$i] * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return (10 - ($sum % 10)) % 10;
    }
}
