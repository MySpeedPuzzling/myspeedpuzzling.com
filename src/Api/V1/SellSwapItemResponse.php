<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

/**
 * One offer of a sell/swap list - what the website's public list shows:
 * the puzzle, listing_type (sell, swap, both, free), price with the seller's
 * currency (the seller's list-wide setting; null when not set or the
 * listing has no price), condition (new, like_new, normal, not_so_good,
 * missing_pieces), the seller's comment, whether the offer is reserved and
 * whether it is published on the marketplace. Who an offer is reserved for
 * is the seller's business and is not exposed. The four trailing objects
 * follow WishlistItemResponse.
 */
final class SellSwapItemResponse
{
    public function __construct(
        public string $item_id,
        public string $puzzle_id,
        public string $puzzle_name,
        public null|string $manufacturer_name,
        public int $pieces_count,
        public null|string $image,
        public string $listing_type,
        public null|float $price,
        public null|string $currency,
        public string $condition,
        public null|string $comment,
        public bool $is_reserved,
        public bool $is_published_on_marketplace,
        public string $added_at,
        public PuzzleStatisticsResponse $statistics,
        public null|PuzzleDifficultyResponse $difficulty,
        public null|TimePredictionResponse $prediction,
        public null|PlayerSolvesResponse $solves,
    ) {
    }
}
