<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

use SpeedPuzzling\Web\Query\GetRecentActivity;
use SpeedPuzzling\Web\Results\RecentActivityItem;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The signed-in viewer's Favorites feed on the Hub (docs/features/navigation INB-04b): its rows are loaded once per
 * request - the Favorites tab's "N new" (favorites_new_count(), rendered above the feed) and the feed itself
 * (RecentActivity) read the same rows, so the count and the highlighted rows always agree and cost one statement.
 * The favorites come from the profile row every page loads anyway (GetRecentActivity::ofPlayerFavorites()).
 *
 * "New" = added after the viewer's last visit of the feed (`player.favorites_seen_at`, INB-04a,
 * RecentActivityItem::isNewSince()). Without a last visit nothing is new and there is no count (decision 50).
 */
final class FavoritesFeed implements ResetInterface
{
    // The Hub's feed: the rows "N new" is counted from - all of them new reads "20+ new"
    public const int LIMIT = 20;

    /** @var array<int, array<RecentActivityItem>> keyed by limit */
    private array $items = [];

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetRecentActivity $getRecentActivity,
    ) {
    }

    /**
     * The viewer's favorites' latest rows - none for guests or without favorites.
     *
     * @return array<RecentActivityItem>
     */
    public function items(int $limit = self::LIMIT): array
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null || $profile->favoritePlayers === []) {
            return [];
        }

        return $this->items[$limit] ??= $this->getRecentActivity->ofPlayerFavorites($limit, $profile->playerId, $profile->favoritePlayers);
    }

    /**
     * How many of the LIMIT loaded rows are new since the last visit (LIMIT = all of them, "20+ new"); null without a
     * last visit, for guests and without favorites.
     */
    public function newCount(): null|int
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null || $profile->favoritesSeenAt === null || $profile->favoritePlayers === []) {
            return null;
        }

        $lastVisit = $profile->favoritesSeenAt;

        return count(array_filter(
            $this->items(),
            static fn (RecentActivityItem $item): bool => $item->isNewSince($lastVisit),
        ));
    }

    public function reset(): void
    {
        $this->items = [];
    }
}
