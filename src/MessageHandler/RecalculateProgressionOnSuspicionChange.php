<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Events\PuzzleSolvingTimeSuspicionChanged;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Services\Badges\BadgeEvaluator;
use SpeedPuzzling\Web\Services\Xp\XpChainRecomputer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A marked time counts nowhere (docs/features/suspicious-times.md) - XP and achievements included: marking takes away
 * what it earned, clearing the mark gives it back, whenever it happens (a moderator, the player's fix, SQL reconciled
 * by the scan). Path independence, docs/features/xp-levels/README.md §Anti-farming.
 */
#[AsMessageHandler]
readonly final class RecalculateProgressionOnSuspicionChange
{
    public function __construct(
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private XpChainRecomputer $xpChainRecomputer,
        private BadgeEvaluator $badgeEvaluator,
    ) {
    }

    public function __invoke(PuzzleSolvingTimeSuspicionChanged $event): void
    {
        $time = $this->puzzleSolvingTimeRepository->findById($event->puzzleSolvingTimeId);

        if ($time === null) {
            // Deleted meanwhile - the deletion flow takes care of both
            return;
        }

        $this->xpChainRecomputer->rebuildChainForEditedSolve($time->id->toString());

        foreach ($time->memberPlayerIds() as $playerId) {
            $this->badgeEvaluator->recalculateForPlayer($playerId);
        }
    }
}
