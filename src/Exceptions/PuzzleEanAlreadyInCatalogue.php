<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A new puzzle's barcode is on a puzzle already - usually it is that puzzle (internal API, POST /internal-api/puzzles).
 */
final class PuzzleEanAlreadyInCatalogue extends ConflictHttpException
{
    /**
     * @param list<string> $puzzleIds
     */
    public function __construct(array $puzzleIds)
    {
        parent::__construct(sprintf(
            'A puzzle with this EAN exists already: %s. Use it, or send "allowDuplicateEan": true if it really is another puzzle.',
            implode(', ', $puzzleIds),
        ));
    }
}
