<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\OauthProvider;

final readonly class UnlinkOauthIdentity
{
    public function __construct(
        public string $userId,
        public OauthProvider $provider,
    ) {
    }
}
