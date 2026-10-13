<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A participant's personal link token (docs/features/online-competitions/README.md §5.4,
 * `competition_participant.online_token`): 128 random bits written in base58 - always 22 characters, no look-alike
 * characters (0, O, I, l), so it survives being read aloud, typed from a printed sheet or put in a QR code. A database
 * token rather than a signed URL: it can be shown again, revoked per person and printed.
 */
final readonly class OnlineToken
{
    public const int LENGTH = 22;

    // Bitcoin's base58 alphabet - the digit '1' is zero
    private const string ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public static function generate(): string
    {
        return self::fromBytes(random_bytes(16));
    }

    /**
     * Base58 of 16 bytes (big-endian), padded with the zero digit to 22 characters. Public for its tests.
     */
    public static function fromBytes(string $bytes): string
    {
        if (strlen($bytes) !== 16) {
            throw new \InvalidArgumentException('An online token is made of exactly 16 bytes.');
        }

        /** @var list<int> $number */
        $number = array_values((array) unpack('C*', $bytes));
        $digits = '';

        // Long division of the byte string by 58, one base58 digit per pass (least significant first)
        while ($number !== []) {
            $remainder = 0;
            $quotient = [];

            foreach ($number as $byte) {
                $accumulator = $remainder * 256 + $byte;
                $digit = intdiv($accumulator, 58);
                $remainder = $accumulator % 58;

                if ($quotient !== [] || $digit !== 0) {
                    $quotient[] = $digit;
                }
            }

            $digits = self::ALPHABET[$remainder] . $digits;
            $number = $quotient;
        }

        return str_pad($digits, self::LENGTH, self::ALPHABET[0], STR_PAD_LEFT);
    }

    /**
     * The shape of a token - checked before a link is looked up, so junk never reaches the database
     */
    public static function looksValid(string $token): bool
    {
        return preg_match('/^[1-9A-HJ-NP-Za-km-z]{' . self::LENGTH . '}$/D', $token) === 1;
    }
}
