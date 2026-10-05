<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class AdminCompetitionMaintainer
{
    public function __construct(
        public string $playerId,
        public null|string $name,
        public string $code,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'playerId' => $this->playerId,
            'name' => $this->name,
            'code' => $this->code,
        ];
    }
}
