<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * The puzzle is in this very round already - a round lists each puzzle once. (Other rounds of the same category may
 * share it - docs/features/competitions-management/shared-puzzles-and-divisions.md.)
 */
final class PuzzleAlreadyInCompetitionRound extends \Exception
{
    public function __construct(
        readonly public string $roundName,
    ) {
        parent::__construct(sprintf('Puzzle is already in round "%s"', $roundName));
    }
}
