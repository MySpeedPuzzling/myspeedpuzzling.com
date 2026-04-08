<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class AttributeReferral
{
    public function __construct(
        public string $subscriberPlayerId,
        public null|string $sessionReferralCode = null,
        public null|string $cookieReferralCode = null,
    ) {
    }
}
