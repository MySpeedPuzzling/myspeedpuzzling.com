<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The competition's puzzles are the puzzles of its tag - a tag other competitions or series carry too would change
 * their puzzles as well.
 */
final class CompetitionTagShared extends ConflictHttpException
{
    public function __construct(string $tagName, int $otherHolders)
    {
        parent::__construct(sprintf(
            'The competition\'s puzzles come from the tag "%s", which %d other competition(s) or series carry too - changing it would change theirs. Nothing was changed.',
            $tagName,
            $otherHolders,
        ));
    }
}
