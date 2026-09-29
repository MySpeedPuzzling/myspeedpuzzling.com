<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Event types of Sign in with Apple server-to-server notifications. Apple's
 * documentation says `account-delete`, the notifications observed in the wild
 * say `account-deleted` - both are accepted.
 */
enum AppleSignInEventType: string
{
    // The user stopped forwarding from their "Hide My Email" relay address
    case EmailDisabled = 'email-disabled';
    // ... and turned forwarding back on
    case EmailEnabled = 'email-enabled';
    // The user disconnected MySpeedPuzzling in their Apple ID settings
    case ConsentRevoked = 'consent-revoked';
    // The Apple ID itself was deleted - its `sub` can never sign in again
    case AccountDeleted = 'account-deleted';

    public static function fromNotification(string $type): null|self
    {
        return $type === 'account-delete' ? self::AccountDeleted : self::tryFrom($type);
    }
}
