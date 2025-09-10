<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetPuzzleCollections;
use SpeedPuzzling\Web\Results\PuzzleCollectionOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class PuzzleCollectionsDropdown
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    #[LiveProp]
    public string $puzzleId = '';

    /**
     * @var null|list<PuzzleCollectionOverview>
     */
    private null|array $collections = null;

    public function __construct(
        readonly private GetPuzzleCollections $getPuzzleCollections,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    /**
     * @return list<PuzzleCollectionOverview>
     */
    public function getCollections(): array
    {
        if ($this->collections !== null) {
            return $this->collections;
        }

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        if ($loggedPlayer === null) {
            return [];
        }

        $this->collections = array_values($this->getPuzzleCollections->byPlayerAndPuzzle(
            $loggedPlayer->playerId,
            $this->puzzleId
        ));

        return $this->collections;
    }

    public function hasCollections(): bool
    {
        return count($this->getCollections()) > 0;
    }

    public function isLoggedIn(): bool
    {
        return $this->retrieveLoggedUserProfile->getProfile() !== null;
    }

    #[LiveListener('puzzle:addedToCollection')]
    public function onPuzzleAddedToCollection(): void
    {
        // Clear cached collections to force refresh
        $this->collections = null;
    }

    #[LiveListener('puzzle:removedFromCollection')]
    public function onPuzzleRemovedFromCollection(): void
    {
        // Clear cached collections to force refresh
        $this->collections = null;
    }
}
