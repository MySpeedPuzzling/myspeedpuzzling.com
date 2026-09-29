<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\OauthProvider;

/**
 * A social sign-in identity was attached to an existing account. Async (the
 * `Events\*` default route) - the security notice mail must never slow down
 * or break the sign-in that caused it.
 *
 * Self-contained on purpose: the notice needs only the account (which existed
 * before the link) and what was linked when. It does not re-read the identity
 * row, so it neither races the commit nor goes missing when the identity is
 * disconnected again before the worker gets to it - the owner should hear
 * about the link either way.
 */
readonly final class OauthIdentityLinked
{
    public function __construct(
        public UuidInterface $userAccountId,
        public OauthProvider $provider,
        public DateTimeImmutable $linkedAt,
    ) {
    }
}
