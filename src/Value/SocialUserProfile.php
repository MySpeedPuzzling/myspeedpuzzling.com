<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a provider proved about the visitor, normalized across Google, Apple
 * and Facebook. `emailVerified` drives the auto-link decision (rule 2 vs 3):
 * Google exposes the `email_verified` claim, Apple only releases verified
 * emails in the id_token, Facebook returns only confirmed emails - a denied
 * email permission arrives here as a null email.
 */
final readonly class SocialUserProfile
{
    public function __construct(
        public OauthProvider $provider,
        public string $providerUserId,
        public null|string $email,
        public bool $emailVerified,
        public null|string $name,
    ) {
    }
}
