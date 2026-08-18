<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\AuthAuditEventType;

/**
 * Deliberately unrouted (sync, like ImportAuth0User): the insert must share the
 * dispatching request's lifecycle so the doctrine_transaction middleware wraps it.
 * Never dispatch directly from auth paths - go through AuthAuditRecorder, which
 * guarantees a failed audit write can never break login.
 */
readonly final class RecordAuthAuditEvent
{
    /**
     * @param null|string $userAccountId set it when the dispatching handler just created the
     *        account in the same (unflushed) transaction - a DB lookup by userId/email cannot
     *        see it yet, while the identity map can
     * @param null|array<string, mixed> $metadata never secrets, never passwords
     */
    public function __construct(
        public AuthAuditEventType $eventType,
        public null|string $userAccountId = null,
        public null|string $userId = null,
        public null|string $email = null,
        public null|string $authenticator = null,
        public null|string $ipAddress = null,
        public null|string $userAgent = null,
        public null|array $metadata = null,
    ) {
    }
}
