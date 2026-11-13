<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Sandbox;

use DateTimeImmutable;

/**
 * How long a sandbox membership lasts: for as long as the sandbox exists. A plain `membership.granted_until`, so every
 * members-only check works unchanged - the same mechanism as an admin grant or a free trial.
 */
final class SandboxMembership
{
    public static function grantedUntil(): DateTimeImmutable
    {
        return new DateTimeImmutable('2099-12-31 23:59:59');
    }
}
