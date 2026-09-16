<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Entity\Puzzle;

/**
 * Turns puzzles into plain arrays for the merge audit trail.
 *
 * The snapshot has to survive the puzzle rows themselves, so everything is
 * flattened to scalars here - no entity references, nothing lazy.
 */
readonly final class PuzzleMergeSnapshotBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function puzzleToArray(Puzzle $puzzle): array
    {
        return [
            'id' => $puzzle->id->toString(),
            'name' => $puzzle->name,
            'alternativeName' => $puzzle->alternativeName,
            'piecesCount' => $puzzle->piecesCount,
            'ean' => $puzzle->ean,
            'identificationNumber' => $puzzle->identificationNumber,
            'manufacturerId' => $puzzle->manufacturer?->id->toString(),
            'manufacturerName' => $puzzle->manufacturer?->name,
            'image' => $puzzle->image,
            'imageRatio' => $puzzle->imageRatio,
            'approved' => $puzzle->approved,
            'isAvailable' => $puzzle->isAvailable,
            'addedByUserId' => $puzzle->addedByUser?->id->toString(),
            'addedAt' => $puzzle->addedAt?->format(DATE_ATOM),
            'hideUntil' => $puzzle->hideUntil?->format(DATE_ATOM),
            'hideImageUntil' => $puzzle->hideImageUntil?->format(DATE_ATOM),
        ];
    }
}
