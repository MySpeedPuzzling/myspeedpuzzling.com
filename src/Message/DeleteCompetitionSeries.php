<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class DeleteCompetitionSeries
{
    public function __construct(
        public string $seriesId,
    ) {
    }
}
