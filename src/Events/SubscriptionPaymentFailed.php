<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

readonly final class SubscriptionPaymentFailed
{
    public function __construct(
        public string $subscriptionId,
    ) {
    }
}
