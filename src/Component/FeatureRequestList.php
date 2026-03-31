<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetFeatureRequests;
use SpeedPuzzling\Web\Results\FeatureRequestOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\FeatureRequestStatus;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class FeatureRequestList
{
    use DefaultActionTrait;

    #[LiveProp(writable: true, url: true)]
    public string $status = '';

    #[LiveProp(writable: true, url: true)]
    public string $sort = 'most_votes';

    #[LiveProp(writable: true, url: true)]
    public bool $my = false;

    #[LiveProp]
    public int $votesUsedThisMonth = 0;

    /** @var null|array<FeatureRequestOverview> */
    private null|array $cachedItems = null;

    public function __construct(
        readonly private GetFeatureRequests $getFeatureRequests,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    /**
     * @return array<FeatureRequestOverview>
     */
    public function getItems(): array
    {
        if ($this->cachedItems !== null) {
            return $this->cachedItems;
        }

        $statusEnum = FeatureRequestStatus::tryFrom($this->status);

        $allowedSorts = ['most_votes', 'least_votes', 'newest', 'oldest'];
        $sort = in_array($this->sort, $allowedSorts, true) ? $this->sort : 'most_votes';

        $authorId = null;
        if ($this->my) {
            $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
            if ($loggedPlayer !== null) {
                $authorId = $loggedPlayer->playerId;
            }
        }

        $this->cachedItems = $this->getFeatureRequests->findAll($statusEnum, $authorId, $sort);

        return $this->cachedItems;
    }
}
