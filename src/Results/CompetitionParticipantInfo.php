<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class CompetitionParticipantInfo
{
    public function __construct(
        public string $participantId,
        public string $participantName,
        /** @var array<string> */
        public array $rounds = [],
    ) {
    }
}
