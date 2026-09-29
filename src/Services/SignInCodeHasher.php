<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Ramsey\Uuid\UuidInterface;
use SensitiveParameter;

/**
 * The 6-digit sign-in code (auth UX redesign phase 2): minted, normalised and
 * hashed here, nowhere else.
 *
 * Only an HMAC-SHA256 of the code is stored: keyed with the app secret, salted
 * with the request's own id. A million possible codes are no secret against a
 * plain hash - a leaked table alone must not hand out codes, and the per-row
 * salt keeps two rows with the same code from looking alike.
 */
final readonly class SignInCodeHasher
{
    public const int LENGTH = 6;

    public function __construct(
        #[SensitiveParameter]
        private string $kernelSecret,
    ) {
    }

    public static function generate(): string
    {
        return str_pad((string) random_int(0, 10 ** self::LENGTH - 1), self::LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * What people paste or type: "123 456", "123-456", a stray tab or a
     * non-breaking space from the mail. Null when it is not six digits after that.
     */
    public static function normalize(string $input): null|string
    {
        $code = preg_replace('/[\s\x{00A0}\x{2007}\x{202F}\-]+/u', '', $input) ?? '';

        return preg_match('/^\d{' . self::LENGTH . '}$/', $code) === 1 ? $code : null;
    }

    public function hash(UuidInterface $requestId, #[SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', $requestId->toString() . ':' . $code, $this->kernelSecret);
    }

    public function matches(UuidInterface $requestId, #[SensitiveParameter] string $code, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash($requestId, $code));
    }
}
