<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * An unfinished result is recorded only for a competition round with a time limit - the round of the time's competition
 * (or of the edition a series pick found by its puzzle), puzzle and solo/pair/team (SolvingTimeRoundResolver). No
 * competition, no such round or a round without a limit: there is nothing it could be unfinished against
 * (docs/features/competitions-management/unfinished-results-plan.md "Refined 2026-11-16", rule 1).
 */
final class UnfinishedResultNeedsTimeLimit extends \Exception
{
}
