<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\OauthProvider;

/**
 * Rule 4 of the settled account-linking rules (D13): no identity row, no email
 * match - create a fresh native account for the provider profile. Only
 * dispatched from the interstitial confirmation (never straight from the OAuth
 * callback - plan §Linking vs merging), and only after the provider proved the
 * profile: the caller owns that proof, the handler cannot check it.
 */
final readonly class RegisterWithOauthIdentity
{
    public function __construct(
        public OauthProvider $provider,
        public string $providerUserId,
        public string $email,
        public bool $emailVerified,
        public null|string $name,
        public null|string $locale,
    ) {
    }
}
