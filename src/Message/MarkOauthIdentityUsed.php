<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\OauthProvider;

/**
 * Rule-1 login bookkeeping: touch oauth_identity.last_used_at (house audit
 * pattern, same as PAT/OAuth2 tokens).
 */
final readonly class MarkOauthIdentityUsed
{
    public function __construct(
        public OauthProvider $provider,
        public string $providerUserId,
    ) {
    }
}
