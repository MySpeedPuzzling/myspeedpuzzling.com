<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\OauthProvider;

readonly final class ConnectedOauthIdentity
{
    public function __construct(
        public OauthProvider $provider,
        public null|string $emailAtLink,
        public DateTimeImmutable $linkedAt,
        public null|DateTimeImmutable $lastUsedAt,
    ) {
    }
}
