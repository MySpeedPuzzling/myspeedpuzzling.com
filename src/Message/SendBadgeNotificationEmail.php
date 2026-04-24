<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

readonly final class SendBadgeNotificationEmail
{
    /**
     * @param list<array{type: BadgeType, tier: null|BadgeTier}> $badgeSummary
     */
    public function __construct(
        public string $playerId,
        public array $badgeSummary,
    ) {
    }
}
