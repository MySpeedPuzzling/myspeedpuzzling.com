<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The player was shown the Hub's Favorites feed (docs/features/navigation, INB-04a) - dispatched by
 * RecentActivity::markFavoritesSeen(), at most once per Hub visit.
 */
readonly final class MarkFavoritesSeen
{
    public function __construct(
        public string $playerId,
    ) {
    }
}
