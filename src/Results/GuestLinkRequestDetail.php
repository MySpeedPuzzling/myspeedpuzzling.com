<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class GuestLinkRequestDetail
{
    public function __construct(
        public string $requestId,
        public string $requesterId,
        public string $requesterLabel,
        public string $guestName,
        public bool $pending,
        public null|bool $accepted,
        /** @var list<PuzzlingTeamTime> The results the guest is part of - what accepting adds to the player's history */
        public array $results,
    ) {
    }
}
