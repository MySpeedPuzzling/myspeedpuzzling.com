<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\RecalculateCommunityStats;
use SpeedPuzzling\Web\Services\Community\CommunityStatsCalculator;
use SpeedPuzzling\Web\Services\Community\PlayerMomentDetector;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs inside the doctrine_transaction middleware: readers see the previous numbers until the whole rebuild commits.
 */
#[AsMessageHandler]
readonly final class RecalculateCommunityStatsHandler
{
    public function __construct(
        private CommunityStatsCalculator $calculator,
        private PlayerMomentDetector $momentDetector,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{players: int, scopes: int, moments_detected: int, moments_written: int, moments_removed: int}
     */
    public function __invoke(RecalculateCommunityStats $message): array
    {
        $now = $this->clock->now();

        $stats = $this->calculator->recalculate($now);
        $moments = $this->momentDetector->detect($now);

        return [
            'players' => $stats['players'],
            'scopes' => $stats['scopes'],
            'moments_detected' => $moments['detected'],
            'moments_written' => $moments['written'],
            'moments_removed' => $moments['removed'],
        ];
    }
}
