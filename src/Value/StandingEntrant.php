<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One person of a standing's entrant as the reader may see them (names as the organiser recorded them).
 */
final readonly class StandingEntrant
{
    public function __construct(
        public string $name,
        public null|string $country = null,
        public null|string $playerId = null,
        public null|string $playerCode = null,
    ) {
    }

    /**
     * @return array{name: string, country: null|string, playerId: null|string, playerCode: null|string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'country' => $this->country,
            'playerId' => $this->playerId,
            'playerCode' => $this->playerCode,
        ];
    }
}
