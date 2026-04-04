<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class ApproveCompetitionSeries
{
    public function __construct(
        public string $seriesId,
        public string $approvedByPlayerId,
    ) {
    }
}
