<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * EditPuzzleSolvingTime reached an unfinished result without saying it ends it (EditPuzzleSolvingTime::$endsUnfinishedResult).
 * That edit knows nothing of pieces placed: it could move the result off its round, onto a puzzle with fewer pieces, or
 * leave it as a relax solve. An unfinished result is edited through EditUnfinishedPuzzleSolvingTime
 * (docs/features/competitions-management/unfinished-results-plan.md, step 3).
 */
final class UnfinishedResultNeedsItsOwnEdit extends \Exception
{
}
