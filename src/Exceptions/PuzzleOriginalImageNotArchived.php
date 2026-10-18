<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * The puzzle's picture could not be copied to the originals archive (PuzzleOriginalImageArchive) - an image pipeline
 * proposal is approved only once the picture it replaces is kept there. Answered `409` with
 * `"code": "original_not_archived"`, nothing is approved; try again later.
 */
final class PuzzleOriginalImageNotArchived extends ConflictHttpException implements InternalApiErrorCode
{
    public function __construct(string $image, null|Throwable $previous = null)
    {
        parent::__construct(
            sprintf('The puzzle\'s picture "%s" could not be copied to the originals archive - nothing was approved. Try again later.', $image),
            $previous,
        );
    }

    public function internalApiErrorCode(): string
    {
        return 'original_not_archived';
    }
}
