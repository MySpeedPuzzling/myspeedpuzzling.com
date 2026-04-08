<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class AttributeTribute
{
    public function __construct(
        public string $subscriberPlayerId,
        public null|string $sessionTributeCode = null,
        public null|string $cookieTributeCode = null,
    ) {
    }
}
