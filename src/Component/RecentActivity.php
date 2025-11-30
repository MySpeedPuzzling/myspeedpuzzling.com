<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Query\GetRecentActivity;
use SpeedPuzzling\Web\Results\RecentActivityItem;
use SpeedPuzzling\Web\Results\PlayerRanking;
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

    public function __construct(
        readonly private GetRecentActivity $getRecentActivity,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetRanking $getRanking,
    ) {
    }

    /**
     * @return array<RecentActivityItem>
     */
    public function getItems(): array
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

    /**
     * @return array<string, PlayerRanking>
     */
    public function getRanking(): array
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return [];
        }

        return $this->getRanking->allForPlayer($profile->playerId);
    }
}
