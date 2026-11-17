<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What one round gives one entrant of a `sum` standing (A-round-formats.md §3.3). Finished and Unfinished score;
 * every other status is a round without a score ("missing"), except NotCounted - a round that does not count yet.
 */
enum StandingScoreStatus: string
{
    case Finished = 'finished';
    // Did not finish: the time limit + the penalty per missing piece
    case Unfinished = 'unfinished';
    // Did not finish in a round without a time limit - nothing to score it with
    case UnfinishedWithoutLimit = 'unfinished_without_limit';
    case DidNotStart = 'did_not_start';
    // In the round, nothing recorded yet
    case NoResult = 'no_result';
    // Not entered in the round at all (a line-up with other people is another entrant)
    case NotInRound = 'not_in_round';
    // The round does not count yet (public: unpublished; organiser: nothing recorded in it at all)
    case NotCounted = 'not_counted';

    public function scores(): bool
    {
        return $this === self::Finished || $this === self::Unfinished;
    }

    public function isMissing(): bool
    {
        return $this->scores() === false && $this !== self::NotCounted;
    }
}
