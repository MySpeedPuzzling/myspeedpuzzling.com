<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnalyticsStudio;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\AnalyticsStudioNotFound;
use SpeedPuzzling\Web\Query\GetAnalyticsStudioRound;
use SpeedPuzzling\Web\Query\RoundPuzzlesVisibleNow;
use SpeedPuzzling\Web\Results\AnalyticsStudioContext;

/**
 * The round and its visible puzzles now - two statements every studio answer pays before its caches (their keys are
 * made of what these say: the stopwatch state and the visible puzzles).
 */
readonly final class AnalyticsStudioContextLoader
{
    public function __construct(
        private GetAnalyticsStudioRound $getAnalyticsStudioRound,
        private RoundPuzzlesVisibleNow $roundPuzzlesVisibleNow,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws AnalyticsStudioNotFound
     */
    public function load(string $roundId): AnalyticsStudioContext
    {
        $round = $this->getAnalyticsStudioRound->byId($roundId);
        $now = $this->clock->now();

        return new AnalyticsStudioContext(
            round: $round,
            puzzles: $this->roundPuzzlesVisibleNow->forRound($round->id, $now),
            now: $now,
        );
    }
}
