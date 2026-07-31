<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\OauthProvider;

/**
 * Attaches a provider identity to an existing account. Two callers, both with
 * ownership already proven (settled rules, D13): rule 2 auto-link during login
 * (provider-verified email matches the account) and rule 5 explicit linking
 * from settings (the user is authenticated; no email match required).
 */
final readonly class LinkOauthIdentity
{
    public function __construct(
        public string $userId,
        public OauthProvider $provider,
        public string $providerUserId,
        public null|string $emailAtLink,
        // Rule-2 auto-link happens mid-login, so the fresh identity is also
        // being used right now; a settings link (rule 5) is not a sign-in
        public bool $usedForLogin = false,
    ) {
    }
}
