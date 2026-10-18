<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What became of one item of a batch of image analyses (POST /internal-api/puzzle-image-analyses): `stored`,
 * `stale_fingerprint` (another picture than the puzzle's current one - `currentFingerprint` says which, null when the
 * puzzle has no image), `not_found` (unknown puzzle or its image hidden) or `invalid` (`errors` by field).
 */
final readonly class PuzzleImageAnalysisOutcome
{
    public const string STORED = 'stored';

    public const string STALE_FINGERPRINT = 'stale_fingerprint';

    public const string NOT_FOUND = 'not_found';

    public const string INVALID = 'invalid';

    /**
     * @param array<string, string> $errors
     */
    private function __construct(
        public string $status,
        public null|string $message = null,
        public null|string $currentFingerprint = null,
        public array $errors = [],
    ) {
    }

    public static function stored(): self
    {
        return new self(self::STORED);
    }

    public static function staleFingerprint(string $message, null|string $currentFingerprint): self
    {
        return new self(self::STALE_FINGERPRINT, $message, $currentFingerprint);
    }

    public static function notFound(string $message): self
    {
        return new self(self::NOT_FOUND, $message);
    }

    /**
     * @param array<string, string> $errors
     */
    public static function invalid(string $message, array $errors): self
    {
        return new self(self::INVALID, $message, errors: $errors);
    }
}
