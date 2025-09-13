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
final class PuzzleActionsDropdown
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    #[LiveProp]
    public string $puzzleId = '';

    #[LiveProp]
    public int $changeCounter = 0;

    /**
     * @var null|list<PuzzleCollectionOverview>
     */
    private null|array $collections = null;

    public function __construct(
        readonly private GetPuzzleCollections $getPuzzleCollections,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[LiveListener('puzzle:addedToCollection')]
    #[LiveListener('puzzle:removedFromCollection')]
    public function onChange(): void
    {
        $this->collections = null;
        $this->changeCounter++;
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
            $this->collections = [];
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
}
