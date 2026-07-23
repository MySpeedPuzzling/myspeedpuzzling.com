<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\BadgeNotFound;
use SpeedPuzzling\Web\Message\RevealBadge;
use SpeedPuzzling\Web\Repository\BadgeRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * First-click badge reveal. The profile strip shows only the highest tier per type,
 * so flipping it also marks the hidden lower tiers as revealed.
 */
#[AsMessageHandler]
readonly final class RevealBadgeHandler
{
    public function __construct(
        private BadgeRepository $badgeRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws BadgeNotFound
     */
    public function __invoke(RevealBadge $message): void
    {
        $badge = $this->badgeRepository->get($message->badgeId);

        if ($badge->player->id->toString() !== $message->playerId) {
            // Only the owner experiences the reveal moment — ignore anyone else.
            return;
        }

        $now = $this->clock->now();

        foreach ($this->badgeRepository->findByPlayerAndType($badge->player->id->toString(), $badge->type) as $sibling) {
            $isSameOrLowerTier = $badge->tier === null
                || ($sibling->tier !== null && $sibling->tier <= $badge->tier);

            if ($isSameOrLowerTier) {
                $sibling->reveal($now);
            }
        }
    }
}
