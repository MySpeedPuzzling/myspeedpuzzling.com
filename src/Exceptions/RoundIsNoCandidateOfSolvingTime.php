<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * ChooseSolvingTimeRound named a round the time cannot be in: not a round of the time's event and solo/pair/team that
 * holds its puzzle (SolvingTimeRoundMatch) - or the time is an imported event result, which keeps the round its import
 * gave it. Nothing changes.
 */
final class RoundIsNoCandidateOfSolvingTime extends \Exception
{
}
