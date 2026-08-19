<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

/**
 * One puzzle of a wishlist: the flat puzzle fields of a collection item, when
 * it was added, and the per-puzzle insight objects (always present, null
 * means "not available to this token" - docs/features/api/README.md, Members-
 * Exclusive Data): statistics is public; difficulty needs the token owner to
 * be a member; prediction is the token owner's own forecast (member, not
 * opted out, PAT or results:read) - on another player's list too, as the
 * website shows the visitor their own predicted time; solves are the list
 * owner's own history (PAT or results:read).
 */
final class WishlistItemResponse
{
    public function __construct(
        public string $wishlist_item_id,
        public string $puzzle_id,
        public string $puzzle_name,
        public null|string $manufacturer_name,
        public int $pieces_count,
        public null|string $image,
        public string $added_at,
        public PuzzleStatisticsResponse $statistics,
        public null|PuzzleDifficultyResponse $difficulty,
        public null|TimePredictionResponse $prediction,
        public null|PlayerSolvesResponse $solves,
    ) {
    }
}
