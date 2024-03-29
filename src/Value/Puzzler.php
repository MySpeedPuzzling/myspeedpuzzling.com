<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Nette\Utils\Json;

readonly final class Puzzler
{
    public function __construct(
        public null|string $playerId,
        public null|string $playerName,
        public null|string $playerCode,
        public null|CountryCode $playerCountry,
    ) {
    }

    /**
     * @return array<self>
     */
    public static function createPuzzlersFromJson(string $json, null|string $excludePlayerId = null): array
    {
        /** @var array<array{player_id: null|string, player_name: null|string, player_country: null|string, player_code?: null|string}> $playersData */
        $playersData = Json::decode($json, true);

        $players = array_map(static function(array $data): Puzzler {
            return new Puzzler(
                playerId: $data['player_id'],
                playerName: $data['player_name'],
                playerCode: $data['player_code'] ?? null,
                playerCountry: CountryCode::fromCode($data['player_country'])
            );
        }, $playersData);

        return array_filter($players, static function(Puzzler $puzzler) use ($excludePlayerId): bool {
            return ($puzzler->playerId === null || $puzzler->playerId !== $excludePlayerId);
        });
    }
}
