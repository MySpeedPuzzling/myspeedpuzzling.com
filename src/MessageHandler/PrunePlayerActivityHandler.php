<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\PrunePlayerActivity;
use SpeedPuzzling\Web\Repository\PlayerActivityDayRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Prunes the per-player raw presence rows - behavioral personal data with a
 * stated retention window. The activity_daily_summary aggregates (no personal
 * data) are deliberately kept forever; snapshot before this window passes.
 */
#[AsMessageHandler]
readonly final class PrunePlayerActivityHandler
{
    public function __construct(
        private PlayerActivityDayRepository $playerActivityDayRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PrunePlayerActivity $message): int
    {
        $before = $this->clock->now()->modify("-{$message->retentionMonths} months");

        return $this->playerActivityDayRepository->deleteOlderThan($before);
    }
}
