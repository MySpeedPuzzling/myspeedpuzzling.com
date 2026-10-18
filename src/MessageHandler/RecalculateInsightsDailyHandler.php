<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\RecalculateInsightsDaily;
use SpeedPuzzling\Web\Services\Insights\GroupCoordinationFitter;
use SpeedPuzzling\Web\Services\Insights\InsightsGeneration;
use SpeedPuzzling\Web\Services\Insights\InsightsProfileRecalculator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs inside the doctrine_transaction middleware, like the community stats: readers see the previous constants and
 * profiles until the whole rebuild commits. Then the insights generation moves on, so cached pages pick them up.
 */
#[AsMessageHandler]
readonly final class RecalculateInsightsDailyHandler
{
    public function __construct(
        private GroupCoordinationFitter $coordinationFitter,
        private InsightsProfileRecalculator $profileRecalculator,
        private InsightsGeneration $generation,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{coordination_written: int, profiles: int, profiles_written: int, profiles_deleted: int, generation: int}
     */
    public function __invoke(RecalculateInsightsDaily $message): array
    {
        $now = $this->clock->now();

        $coordination = $this->coordinationFitter->refit($now);
        $profiles = $this->profileRecalculator->recalculate($now);

        return [
            'coordination_written' => $coordination['written'],
            'profiles' => $profiles['players'],
            'profiles_written' => $profiles['written'],
            'profiles_deleted' => $profiles['deleted'],
            'generation' => $this->generation->advance(),
        ];
    }
}
