<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class FreeTrialFunnel
{
    public function __construct(
        /** Players with no membership row - everybody who could still start a trial */
        public int $eligiblePlayers,
        public int $started,
        public int $running,
        public int $ended,
        /** Subscribed while the trial was still running */
        public int $subscribedDuringTrial,
        /** Subscribed at any time since starting the trial */
        public int $subscribedTotal,
        /** @var array<string, int> FreeTrialSource value => trials started */
        public array $startedBySource,
        /** @var array<string, int> FreeTrialSource value => of those, subscribed */
        public array $subscribedBySource,
    ) {
    }

    public function conversionPercent(): null|float
    {
        return $this->started > 0 ? round($this->subscribedTotal / $this->started * 100, 1) : null;
    }
}
