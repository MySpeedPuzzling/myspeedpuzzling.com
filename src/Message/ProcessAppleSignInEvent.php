<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\AppleSignInEventType;

/**
 * A verified Sign in with Apple server-to-server notification
 * (AppleSignInNotificationController).
 */
final readonly class ProcessAppleSignInEvent
{
    public function __construct(
        public AppleSignInEventType $type,
        public string $providerUserId,
    ) {
    }
}
