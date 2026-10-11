<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Services\Badges\BadgeEvaluator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Persists every achievement tier the player qualifies for. Nothing is mailed: achievements reach players in the app
 * (the post-solve recap, the profile, the reveal pages) and in the activity digest - never as a mail of their own
 * (docs/features/xp-levels/launch-2026-10.md).
 */
#[AsMessageHandler]
readonly final class RecalculateBadgesForPlayerHandler
{
    public function __construct(
        private BadgeEvaluator $badgeEvaluator,
    ) {
    }

    public function __invoke(RecalculateBadgesForPlayer $message): void
    {
        $this->badgeEvaluator->recalculateForPlayer($message->playerId);
    }
}
