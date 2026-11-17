<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Results\PublishedEntryPuzzle;
use SpeedPuzzling\Web\Results\RoundPuzzlesNow;

/**
 * The pieces an unfinished result is out of, when the public may know them (competitor pages, the analytics studio -
 * one rule, so the two never disagree): a one-result round's one puzzle, or all K puzzles a pick entry chose - each
 * shown to everybody (RoundPuzzlesVisibleNow). Null whenever any of it is still hidden.
 */
final class UnfinishedPiecesCount
{
    /**
     * @param list<PublishedEntryPuzzle> $chips the entry's chosen puzzles (pick rounds), PublishedEntryPuzzle::listOf()
     */
    public static function of(bool $isPick, null|int $toSolve, array $chips, RoundPuzzlesNow $puzzles): null|int
    {
        if ($isPick === false) {
            return count($puzzles->slots) === 1 && count($puzzles->puzzles) === 1 ? $puzzles->puzzles[0]->piecesCount : null;
        }

        if ($toSolve === null || count($chips) !== $toSolve) {
            return null;
        }

        $pieces = 0;
        foreach ($chips as $chip) {
            if ($chip->puzzle === null) {
                return null;
            }

            $pieces += $chip->puzzle->piecesCount;
        }

        return $pieces;
    }
}
