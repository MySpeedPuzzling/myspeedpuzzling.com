<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * An image analysis of a picture the puzzle no longer has (its imageFingerprint is not PuzzleImageFingerprint of the
 * puzzle's current image, or the puzzle has no image) - answered `409`, nothing stored: list the puzzle again and
 * analyse its current picture.
 */
final class PuzzleImageChangedMeanwhile extends ConflictHttpException
{
    public function __construct(
        readonly public null|string $currentFingerprint,
    ) {
        parent::__construct($currentFingerprint === null
            ? 'The puzzle has no image any more - nothing was stored.'
            : sprintf('The puzzle\'s image changed since it was analysed (its current imageFingerprint is "%s") - nothing was stored.', $currentFingerprint));
    }
}
