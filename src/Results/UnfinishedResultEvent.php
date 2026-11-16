<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Value\SeriesEditionMatchKind;

/**
 * What UnfinishedResultRound settled: the one-time event or edition, its round with a time limit, and - for a series
 * pick - the series and how its edition was found (always by its puzzle).
 */
readonly final class UnfinishedResultEvent
{
    public function __construct(
        public Competition $competition,
        public CompetitionRound $round,
        public null|CompetitionSeries $series,
        public null|SeriesEditionMatchKind $seriesEditionMatch,
    ) {
    }

    public function minutesLimit(): int
    {
        // UnfinishedResultRound returns only a round with a limit
        assert($this->round->minutesLimit !== null);

        return $this->round->minutesLimit;
    }
}
