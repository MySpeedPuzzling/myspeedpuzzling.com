<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class RejectCompetitionSeries
{
    public function __construct(
        public string $seriesId,
        public string $rejectedByPlayerId,
        public string $reason,
    ) {
    }
}
