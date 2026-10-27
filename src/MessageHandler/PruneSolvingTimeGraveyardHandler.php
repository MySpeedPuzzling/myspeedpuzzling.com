<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\PruneSolvingTimeGraveyard;
use SpeedPuzzling\Web\Repository\SolvingTimeGraveyardRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Graves hold a deleted result whole (a comment, a photo path) - personal data kept for recovery only, so not
 * forever: 24 months (Jan, 2026-10-27; docs/features/api/solving-time-sync.md). Tombstones go at the same age.
 */
#[AsMessageHandler]
readonly final class PruneSolvingTimeGraveyardHandler
{
    public function __construct(
        private SolvingTimeGraveyardRepository $graveyardRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PruneSolvingTimeGraveyard $message): int
    {
        $before = $this->clock->now()->modify("-{$message->retentionMonths} months");

        return $this->graveyardRepository->deleteOlderThan($before);
    }
}
