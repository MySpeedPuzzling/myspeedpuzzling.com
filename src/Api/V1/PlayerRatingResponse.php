<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

/**
 * One row of the MSP Rating block on the profile page: the player's rating
 * for a piece count, the same number the website shows (points = the stored
 * elo rating scaled by 1000, rounded - PlayerRatingEntry::displayRating()),
 * and the rank among the ranked players of that piece count.
 *
 * An endpoint returns null for the whole list when the rating is not
 * available to the token (the player opted out of rankings, or the profile
 * is private and masked); an empty list means "not ranked yet".
 */
final class PlayerRatingResponse
{
    public function __construct(
        public int $pieces_count,
        public int $points,
        public int $rank,
        public int $total_players,
    ) {
    }

    /**
     * @param array{elo_rating: float, rank: int, total: int} $rating one entry of GetPlayerRatingRanking::allForPlayer()
     */
    public static function fromRating(int $piecesCount, array $rating): self
    {
        return new self(
            pieces_count: $piecesCount,
            points: (int) round($rating['elo_rating'] * 1000),
            rank: $rating['rank'],
            total_players: $rating['total'],
        );
    }
}
