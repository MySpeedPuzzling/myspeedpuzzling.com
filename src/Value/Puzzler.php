<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Nette\Utils\Json;

readonly final class Puzzler
{
    public function __construct(
        public null|string $playerId,
        public null|string $playerName,
    ) {
    }

    /**
     * @return array<self>
     */
    public static function createPuzzlersFromJson(string $json): array
    {
        /** @var array<array{player_id: null|string, player_name: null|string}> $playersData */
        $playersData = Json::decode($json, true);

        return array_map(static function(array $data): Puzzler {
            return new Puzzler(
                playerId: $data['player_id'],
                playerName: $data['player_name'],
            );
        }, $playersData);
    }
}
