<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * The unfinished result's puzzle is in several rounds of its category at the event and nothing about the result singles
 * out one of them (SolvingTimeRoundMatch - docs/features/competitions-management/shared-puzzles-and-divisions.md §2.4
 * row 7): without its round there is no limit it could be unfinished against. A subclass of
 * UnfinishedResultNeedsTimeLimit, so every existing catch keeps refusing it; the forms and the API say why with a
 * message of its own - add it from the round's official results ("Add to my profile" names the round).
 */
final class UnfinishedResultRoundNotIdentified extends UnfinishedResultNeedsTimeLimit
{
}
