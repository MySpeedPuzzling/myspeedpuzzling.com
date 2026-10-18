<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The image pipeline proposed this already: a request of the same puzzle, picture (sourceImageFingerprint) and tag
 * exists in any status - a rejected one is remembered, a new picture gets a new fingerprint. Answered `409` with
 * `"code": "already_proposed"`, nothing is filed.
 */
final class PuzzleImageAlreadyProposed extends ConflictHttpException implements InternalApiErrorCode
{
    public function __construct(
        readonly public string $existingChangeRequestId,
        readonly public string $tag,
    ) {
        parent::__construct(sprintf(
            'This picture of the puzzle was already proposed with the tag "%s" (change request %s) - nothing was filed.',
            $tag,
            $existingChangeRequestId,
        ));
    }

    public function internalApiErrorCode(): string
    {
        return 'already_proposed';
    }
}
