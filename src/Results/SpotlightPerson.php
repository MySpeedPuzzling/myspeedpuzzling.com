<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CountryCode;

/**
 * One person in a list of the Players page spotlight (Most active this month, Most followed, New faces), with the
 * precomputed numbers each list shows (docs/features/players-page/README.md).
 */
readonly final class SpotlightPerson
{
    public function __construct(
        public string $playerId,
        public string $playerCode,
        public null|string $playerName,
        public null|CountryCode $playerCountry,
        public null|string $playerAvatar,
        // Equal values share a rank (1, 2, 2, 4)
        public int $rank,
        public DateTimeImmutable $registeredAt,
        public int $solvedTotal,
        public int $solvesThisMonth,
        public int $piecesThisMonth,
        public int $favoritesCount,
    ) {
    }

    /**
     * @param array{
     *     player_id: string,
     *     player_code: string,
     *     player_name: null|string,
     *     player_country: null|string,
     *     player_avatar: null|string,
     *     list_rank: int|string,
     *     registered_at: string,
     *     solved_total: int|string,
     *     solves_this_month: int|string,
     *     pieces_this_month: int|string,
     *     favorites_count: int|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            playerId: $row['player_id'],
            playerCode: $row['player_code'],
            playerName: $row['player_name'],
            playerCountry: CountryCode::fromCode($row['player_country']),
            playerAvatar: $row['player_avatar'],
            rank: (int) $row['list_rank'],
            registeredAt: new DateTimeImmutable($row['registered_at']),
            solvedTotal: (int) $row['solved_total'],
            solvesThisMonth: (int) $row['solves_this_month'],
            piecesThisMonth: (int) $row['pieces_this_month'],
            favoritesCount: (int) $row['favorites_count'],
        );
    }
}
