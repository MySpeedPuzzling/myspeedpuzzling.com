<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * A puzzle may be in only one round per category (solo / duo / team) of a competition - that is what lets
 * a solving time's round follow from its competition + puzzle alone.
 */
final class PuzzleAlreadyInCompetitionRoundCategory extends \Exception
{
    public function __construct(
        readonly public string $conflictingRoundName,
    ) {
        parent::__construct(sprintf('Puzzle is already in round "%s" of the same category', $conflictingRoundName));
    }
}
