<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * The decision the player changed is not the puzzle's current one any more (another tab, another device, a run of
 * the pairing) - nothing was written.
 */
#[WithHttpStatus(Response::HTTP_CONFLICT)]
final class ImportPuzzleDecidedMeanwhile extends \DomainException
{
    /**
     * @param list<string> $importPuzzleIds
     */
    public function __construct(
        public readonly array $importPuzzleIds,
    ) {
        parent::__construct('A puzzle of the import was decided meanwhile.');
    }
}
