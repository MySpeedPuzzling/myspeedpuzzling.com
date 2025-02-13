<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;

readonly final class MembershipTrialEnded
{
    public function __construct(
        public UuidInterface $membershipId,
    ) {
    }
}
