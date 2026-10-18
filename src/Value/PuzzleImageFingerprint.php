<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Which picture a puzzle has: a short hash of its image path (puzzle.image). A new picture always gets a file name
 * never written before (PuzzleImageNamer, since 2026-10-07 - the image caches key on the name), so the path names the
 * picture and nothing has to be stored on the puzzle. The fingerprint changes too when the same picture moves to
 * another name (SecretPuzzleHides renames a secret puzzle's guessable file) - its analyses then read as stale and the
 * picture is analysed once more: it costs a run, never a wrong answer.
 *
 * md5 only fingerprints a path here, it protects nothing. SQL computes the same value (sql()) - the image list filters
 * by it; PuzzleImageFingerprintTest pins both to the same answer.
 */
final class PuzzleImageFingerprint
{
    public const int LENGTH = 16;

    public static function of(string $imagePath): string
    {
        return substr(md5($imagePath), 0, self::LENGTH);
    }

    /**
     * The fingerprint of an image column - NULL for a puzzle without an image.
     */
    public static function sql(string $imageColumn): string
    {
        return sprintf('left(md5(%s), %d)', $imageColumn, self::LENGTH);
    }
}
