<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class CreateCompetitionTeams
{
    /**
     * @param list<string> $names one team per name, an empty list adds one unnamed team
     */
    public function __construct(
        public string $roundId,
        public array $names,
    ) {
    }
}
