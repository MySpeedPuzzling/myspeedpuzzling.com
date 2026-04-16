<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Entity\Badge;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\SendBadgeNotificationEmail;
use SpeedPuzzling\Web\Services\Badges\BadgeEvaluator;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class RecalculateBadgesForPlayerHandler
{
    public function __construct(
        private BadgeEvaluator $badgeEvaluator,
        private MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(RecalculateBadgesForPlayer $message): void
    {
        $newBadges = $this->badgeEvaluator->recalculateForPlayer($message->playerId);

        if ($newBadges === []) {
            return;
        }

        $this->commandBus->dispatch(new SendBadgeNotificationEmail(
            playerId: $message->playerId,
            badgeSummary: $this->keepHighestTierPerType($newBadges),
        ));
    }

    /**
     * @param list<Badge> $badges
     * @return list<array{type: BadgeType, tier: null|BadgeTier}>
     */
    private function keepHighestTierPerType(array $badges): array
    {
        $bestByType = [];

        foreach ($badges as $badge) {
            $tier = $badge->tier === null ? null : BadgeTier::from($badge->tier);
            $typeKey = $badge->type->value;
            $existing = $bestByType[$typeKey] ?? null;

            if ($existing === null) {
                $bestByType[$typeKey] = [
                    'type' => $badge->type,
                    'tier' => $tier,
                ];
                continue;
            }

            $existingTierValue = $existing['tier'] === null ? 0 : $existing['tier']->value;
            $newTierValue = $tier === null ? 0 : $tier->value;

            if ($newTierValue > $existingTierValue) {
                $bestByType[$typeKey] = [
                    'type' => $badge->type,
                    'tier' => $tier,
                ];
            }
        }

        return array_values($bestByType);
    }
}
