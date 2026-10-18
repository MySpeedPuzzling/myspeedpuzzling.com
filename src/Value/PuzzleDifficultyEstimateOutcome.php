<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What became of one item of `PUT /internal-api/puzzle-difficulty-estimates`: `stored`, `stale_fingerprint` (made
 * from another picture than the puzzle's current one - `currentFingerprint` says which, null when the puzzle has no
 * image), `hidden` (a secret puzzle or picture - `hide_until` / `hide_image_until` ahead: never an estimate),
 * `unknown_puzzle` or `invalid` (`errors` by field).
 */
final readonly class PuzzleDifficultyEstimateOutcome
{
    public const string STORED = 'stored';

    public const string STALE_FINGERPRINT = 'stale_fingerprint';

    public const string HIDDEN = 'hidden';

    public const string UNKNOWN_PUZZLE = 'unknown_puzzle';

    public const string INVALID = 'invalid';

    /** @var list<string> */
    public const array STATUSES = [self::STORED, self::STALE_FINGERPRINT, self::HIDDEN, self::UNKNOWN_PUZZLE, self::INVALID];

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

    public static function staleFingerprint(null|string $currentFingerprint): self
    {
        return new self(
            self::STALE_FINGERPRINT,
            $currentFingerprint === null
                ? 'The puzzle has no image any more - nothing was stored.'
                : sprintf('The puzzle\'s image changed since the estimate was made (its current imageFingerprint is "%s") - nothing was stored.', $currentFingerprint),
            $currentFingerprint,
        );
    }

    public static function hidden(): self
    {
        return new self(self::HIDDEN, 'The puzzle or its picture is secret for now - it never gets an estimate.');
    }

    public static function unknownPuzzle(): self
    {
        return new self(self::UNKNOWN_PUZZLE, 'No such puzzle.');
    }

    /**
     * @param array<string, string> $errors
     */
    public static function invalid(string $message, array $errors): self
    {
        return new self(self::INVALID, $message, errors: $errors);
    }
}
