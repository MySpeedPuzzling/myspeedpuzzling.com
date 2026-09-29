<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One Sign in with Apple server-to-server notification, taken from an event
 * token whose signature, issuer and audience were already verified
 * (AppleServerNotificationVerifier).
 */
final readonly class AppleSignInEvent
{
    public function __construct(
        // Null for an event type Apple may add in the future - acknowledged, ignored
        public null|AppleSignInEventType $type,
        public string $rawType,
        // The Apple user id - the same `sub` as oauth_identity.provider_user_id
        public string $providerUserId,
    ) {
    }
}
