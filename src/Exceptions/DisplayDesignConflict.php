<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The design was saved meanwhile (another tab, another organiser): the editor's base version is stale. The save
 * endpoint answers 409 with the current version - the editor offers to load it or to overwrite it.
 */
final class DisplayDesignConflict extends ConflictHttpException
{
    public function __construct(
        public readonly int $currentVersion,
    ) {
        parent::__construct(sprintf('The display design is at version %d already', $currentVersion));
    }
}
