<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class ApproveCompetition
{
    public function __construct(
        public string $competitionId,
        public string $approvedByPlayerId,
        // The "approved" e-mail to the competition's creator - only the internal API leaves it out, when the admin
        // it acts as created the competition himself
        public bool $notifyCreator = true,
    ) {
    }
}
