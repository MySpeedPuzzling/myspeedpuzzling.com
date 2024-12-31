<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class UpdateMembershipSubscription
{
    public function __construct(
        public string $stripeSubscriptionId,
    ) {
    }
}
