<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class ImportCompetitionParticipants
{
    public function __construct(
        public string $competitionId,
        public string $filePath,
    ) {
    }
}
