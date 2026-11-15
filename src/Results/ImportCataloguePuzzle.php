<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\PuzzleNames;

/**
 * A catalogue puzzle as the import's pairing screens offer it (docs/features/data-import/pairing-screens.md): its
 * picture, names, brand and pieces - and why it is offered for a puzzle of the file.
 */
readonly final class ImportCataloguePuzzle
{
    public function __construct(
        public string $puzzleId,
        public string $name,
        public PuzzleNames $alternativeNames,
        public null|string $brandName,
        public int $piecesCount,
        public null|string $image,
        public null|float $imageRatio,
        /**
         * Why it is offered: a `how` of the pairing (`barcode`, `name`, `own_result`, `similar_name` …), `name_brand`
         * (the same name, pieces and brand), `chosen` (the player picked it), `remembered` (decided in an earlier
         * import), `search` (found by the player's search)
         */
        public string $how,
        /** The file says another piece count (more than one apart) - shown prominently, never chosen in a group */
        public bool $piecesDiffer,
    ) {
    }
}
