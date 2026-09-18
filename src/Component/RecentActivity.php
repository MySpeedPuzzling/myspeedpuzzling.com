<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetPlayerBestSoloTimes;
use SpeedPuzzling\Web\Query\GetRecentActivity;
use SpeedPuzzling\Web\Results\RecentActivityItem;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
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

    /** @var null|array<RecentActivityItem> */
    private null|array $cachedItems = null;

    /** @var null|array<string, int> */
    private null|array $cachedMyBestTimes = null;

    public function __construct(
        readonly private GetRecentActivity $getRecentActivity,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPlayerBestSoloTimes $getPlayerBestSoloTimes,
    ) {
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
