<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\MarkFavoritesSeen;
use SpeedPuzzling\Web\Query\GetPlayerBestSoloTimes;
use SpeedPuzzling\Web\Query\GetRecentActivity;
use SpeedPuzzling\Web\Results\RecentActivityItem;
use SpeedPuzzling\Web\Services\ResolveDifficultyTiers;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\FavoritesSeen;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class RecentActivity
{
    use DefaultActionTrait;

    #[LiveProp]
    public int $limit = 20;

    #[LiveProp]
    public null|string $playerId = null;

    #[LiveProp]
    public bool $favoritesOnly = false;

    #[LiveProp]
    public int $showLimit = 0;

    #[LiveProp]
    public bool $isOnProfile = false;

    /**
     * Refreshes itself every minute while somebody looks, with the "Auto-update in …" status line
     * (controllers/live_refresh_controller.js) - the Hub and the global Recent activity page only.
     */
    #[LiveProp]
    public bool $autoRefresh = false;

    /**
     * The Favorites feed only: the viewer's last visit as the profile row had it when the Hub was rendered
     * (`player.favorites_seen_at`, docs/features/navigation INB-04a). Rows tracked after it are new. Not writable and
     * never refreshed from the profile, so marking the feed seen (markFavoritesSeen()) and every automatic refresh of
     * this visit keep the same rows new. Null = no last visit: nothing is new, no count.
     */
    #[LiveProp]
    public null|DateTimeImmutable $newSince = null;

    /** @var null|array<RecentActivityItem> */
    private null|array $cachedItems = null;

    /** @var null|array<string, int> */
    private null|array $cachedMyBestTimes = null;

    /** @var null|array<string, DifficultyTier> */
    private null|array $cachedDifficultyTiers = null;

    private bool $difficultyTiersResolved = false;

    public function __construct(
        readonly private GetRecentActivity $getRecentActivity,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPlayerBestSoloTimes $getPlayerBestSoloTimes,
        readonly private ResolveDifficultyTiers $resolveDifficultyTiers,
        readonly private MessageBusInterface $messageBus,
        readonly private ClockInterface $clock,
    ) {
    }

    /**
     * The Favorites feed was shown in this Hub visit (assets/controllers/favorites_seen_controller.js - once per visit,
     * never from an automatic refresh): its rows count as seen from now on. Changes nothing on this page - `newSince`
     * stays what the page was rendered with.
     */
    #[LiveAction]
    public function markFavoritesSeen(): void
    {
        if ($this->favoritesOnly === false) {
            return;
        }

        $profile = $this->retrieveLoggedUserProfile->getProfile();

        // Marked less than ten minutes ago: the handler would change nothing (it decides, this only saves the trip)
        if ($profile === null || FavoritesSeen::isDue($profile->favoritesSeenAt, $this->clock->now()) === false) {
            return;
        }

        $this->messageBus->dispatch(new MarkFavoritesSeen($profile->playerId));
    }

    /**
     * Added after the viewer's last visit of their Favorites feed - false everywhere else.
     */
    public function isNew(RecentActivityItem $item): bool
    {
        return $this->favoritesOnly && $item->isNewSince($this->newSince);
    }

    /**
     * How many of the loaded rows are new since the last visit; null without a last visit (nothing to count from) and
     * outside the Favorites feed.
     */
    public function getNewCount(): null|int
    {
        if ($this->favoritesOnly === false || $this->newSince === null) {
            return null;
        }

        return count(array_filter($this->getItems(), $this->isNew(...)));
    }

    /**
     * @return array<RecentActivityItem>
     */
    public function getItems(): array
    {
        // The template reads the items more than once
        return $this->cachedItems ??= $this->fetchItems();
    }

    /**
     * The signed-in player's best solo time on the listed puzzles, for the "My time" line.
     *
     * @return array<string, int> seconds keyed by puzzle id
     */
    public function getMyBestTimes(): array
    {
        if ($this->cachedMyBestTimes !== null) {
            return $this->cachedMyBestTimes;
        }

        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return $this->cachedMyBestTimes = [];
        }

        $puzzleIds = array_values(array_unique(array_map(
            static fn (RecentActivityItem $item): string => $item->puzzleId,
            $this->getItems(),
        )));

        return $this->cachedMyBestTimes = $this->getPlayerBestSoloTimes->forPuzzles($profile->playerId, $puzzleIds);
    }

    /**
     * Difficulty tier of the listed puzzles for the thumbnails' corner - members only, null for everyone else.
     *
     * @return null|array<string, DifficultyTier>
     */
    public function getDifficultyTiers(): null|array
    {
        if ($this->difficultyTiersResolved === false) {
            $this->cachedDifficultyTiers = $this->resolveDifficultyTiers->forViewer(
                $this->retrieveLoggedUserProfile->getProfile(),
                array_map(static fn (RecentActivityItem $item): string => $item->puzzleId, $this->getItems()),
            );
            $this->difficultyTiersResolved = true;
        }

        return $this->cachedDifficultyTiers;
    }

    /**
     * @return array<RecentActivityItem>
     */
    private function fetchItems(): array
    {
        if ($this->playerId !== null) {
            return $this->getRecentActivity->forPlayer($this->playerId, $this->limit);
        }

        if ($this->favoritesOnly) {
            $profile = $this->retrieveLoggedUserProfile->getProfile();

            if ($profile === null) {
                return [];
            }

            return $this->getRecentActivity->ofPlayerFavorites($this->limit, $profile->playerId);
        }

        return $this->getRecentActivity->latest($this->limit);
    }
}
