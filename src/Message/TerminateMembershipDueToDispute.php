<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class TerminateMembershipDueToDispute
{
    public function __construct(
        public string $stripeDisputeId,
    ) {
    }
}
