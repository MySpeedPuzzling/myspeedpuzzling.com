<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetPuzzleCollections;
use SpeedPuzzling\Web\Results\PuzzleCollectionOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
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

    /**
     * @var list<string|null>
     */
    public array $collectionIds = [];

    #[LiveProp]
    public int $random = 0;

    #[LiveProp]
    public bool $forceRerender = false;

    /**
     * @var null|list<PuzzleCollectionOverview>
     */
    private null|array $collections = null;

    public function __construct(
        readonly private GetPuzzleCollections $getPuzzleCollections,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[LiveAction]
    public function changeNumber(): void
    {
    }

    public function getNumber(): int
    {
        return mt_rand(1, 1000);
    }

    #[LiveListener('puzzle:addedToCollection')]
    #[LiveListener('puzzle:removedFromCollection')]
    public function onChange(): void
    {
        // Reset cached collections and toggle property to force re-render
        $this->collections = null;
        $this->forceRerender = !$this->forceRerender;
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

        $this->collectionIds = array_map(
            callback: fn (PuzzleCollectionOverview $item): null|string => $item->collectionId,
            array: $this->collections,
        );

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
