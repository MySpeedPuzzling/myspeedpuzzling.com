<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class ApproveCompetitionSeries
{
    public function __construct(
        public string $seriesId,
        public string $approvedByPlayerId,
        // The "approved" e-mail to its creator - false when the creator is the one approving (internal API)
        public bool $notifyCreator = true,
    ) {
    }
}
