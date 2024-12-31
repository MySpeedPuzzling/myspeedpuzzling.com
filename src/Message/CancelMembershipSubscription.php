<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class CancelMembershipSubscription
{
    public function __construct(
        public string $stripeSubscriptionId,
    ) {
    }
}
