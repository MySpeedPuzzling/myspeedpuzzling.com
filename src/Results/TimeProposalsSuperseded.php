<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What ResetRoundStopwatchHandler returns: the round's pending time proposals it superseded (competition toolkit P1) -
 * timed against a stopwatch that is gone. The controller publishes them after the commit.
 */
readonly final class TimeProposalsSuperseded
{
    public function __construct(
        public string $roundId,
        /** @var list<string> */
        public array $proposalIds,
    ) {
    }
}
