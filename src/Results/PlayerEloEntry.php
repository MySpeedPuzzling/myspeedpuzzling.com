<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\SkillTier;

readonly final class PlayerEloEntry
{
    public function __construct(
        public string $playerId,
        public null|string $playerName,
        public null|string $playerCode,
        public null|string $playerCountry,
        public null|string $playerAvatar,
        public float $eloRating,
        public int $rank,
        public null|string $skillTierName = null,
    ) {
    }

    /**
     * Display-friendly rating scaled to traditional ELO range (roughly 500–1250).
     */
    public function displayRating(): int
    {
        return (int) round($this->eloRating * 1000);
    }

    /**
     * @param array{
     *     player_id: string,
     *     player_name: null|string,
     *     player_code: string,
     *     player_country: null|string,
     *     player_avatar: null|string,
     *     elo_rating: float|string,
     *     skill_tier: null|int|string,
     *     rank: int|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $skillTierName = $row['skill_tier'] !== null
            ? strtolower(SkillTier::from((int) $row['skill_tier'])->name)
            : null;

        return new self(
            playerId: $row['player_id'],
            playerName: $row['player_name'],
            playerCode: $row['player_code'],
            playerCountry: $row['player_country'],
            playerAvatar: $row['player_avatar'],
            eloRating: (float) $row['elo_rating'],
            rank: (int) $row['rank'],
            skillTierName: $skillTierName,
        );
    }
}
