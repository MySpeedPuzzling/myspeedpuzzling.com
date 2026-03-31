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
        public bool $isPrivate,
        public null|string $skillTierName = null,
        public bool $rankingOptedOut = false,
    ) {
    }


    /**
     * @param array{
     *     player_id: null|string,
     *     player_name: null|string,
     *     player_code: null|string,
     *     player_country: null|string,
     *     is_private: null|bool,
     *     skill_tier?: null|int|string,
     *     ranking_opted_out?: null|bool,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $skillTierRaw = $row['skill_tier'] ?? null;
        $skillTierName = $skillTierRaw !== null
            ? strtolower(SkillTier::from((int) $skillTierRaw)->name)
            : null;

        return new self(
            playerId: $row['player_id'],
            playerName: $row['player_name'],
            playerCode: $row['player_code'] !== null ? strtoupper($row['player_code']) : null,
            playerCountry: CountryCode::fromCode($row['player_country']),
            isPrivate: $row['is_private'] === null ? false : $row['is_private'],
            skillTierName: $skillTierName,
            rankingOptedOut: ($row['ranking_opted_out'] ?? null) === null ? false : (bool) $row['ranking_opted_out'],
        );
    }

    /**
     * @return array<self>
     */
    public static function createPuzzlersFromJson(string $json, null|string $excludePlayerId = null): array
    {
        /**
         * @var array<array{
         *     player_id: null|string,
         *     player_name: null|string,
         *     player_country: null|string,
         *     is_private: null|bool,
         *     player_code?: null|string,
         *     skill_tier?: null|int|string,
         *     ranking_opted_out?: null|bool,
         *  }> $playersData */
        $playersData = Json::decode($json, true);

        $players = array_map(static function (array $data): Puzzler {
            $playerCode = $data['player_code'] ?? null;
            $skillTierRaw = $data['skill_tier'] ?? null;
            $skillTierName = $skillTierRaw !== null
                ? strtolower(SkillTier::from((int) $skillTierRaw)->name)
                : null;

            return new Puzzler(
                playerId: $data['player_id'],
                playerName: $data['player_name'],
                playerCode: $playerCode !== null ? strtoupper($playerCode) : null,
                playerCountry: CountryCode::fromCode($data['player_country']),
                isPrivate: $data['is_private'] ?? false,
                skillTierName: $skillTierName,
                rankingOptedOut: ($data['ranking_opted_out'] ?? null) === null ? false : (bool) $data['ranking_opted_out'],
            );
        }, $playersData);

        return array_filter($players, static function (Puzzler $puzzler) use ($excludePlayerId): bool {
            return ($puzzler->playerId === null || $puzzler->playerId !== $excludePlayerId);
        });
    }
}
