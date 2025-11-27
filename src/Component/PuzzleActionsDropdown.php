<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetBorrowedPuzzles;
use SpeedPuzzling\Web\Query\GetLentPuzzles;
use SpeedPuzzling\Web\Query\GetPuzzleCollections;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Query\GetWishListItems;
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

    private null|bool $inWishList = null;

    private null|bool $inSellSwapList = null;

    private null|bool $inLentList = null;

    private null|bool $inBorrowedList = null;

    public function __construct(
        readonly private GetPuzzleCollections $getPuzzleCollections,
        readonly private GetWishListItems $getWishListItems,
        readonly private GetSellSwapListItems $getSellSwapListItems,
        readonly private GetLentPuzzles $getLentPuzzles,
        readonly private GetBorrowedPuzzles $getBorrowedPuzzles,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[LiveListener('puzzle:addedToCollection')]
    #[LiveListener('puzzle:removedFromCollection')]
    #[LiveListener('puzzle:addedToWishList')]
    #[LiveListener('puzzle:removedFromWishList')]
    #[LiveListener('puzzle:addedToSellSwapList')]
    #[LiveListener('puzzle:removedFromSellSwapList')]
    #[LiveListener('puzzle:lent')]
    #[LiveListener('puzzle:returned')]
    #[LiveListener('puzzle:borrowed')]
    public function onChange(): void
    {
        $this->collections = null;
        $this->inWishList = null;
        $this->inSellSwapList = null;
        $this->inLentList = null;
        $this->inBorrowedList = null;
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

    public function isPuzzleInWishList(): bool
    {
        if ($this->inWishList !== null) {
            return $this->inWishList;
        }

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        if ($loggedPlayer === null) {
            $this->inWishList = false;
            return false;
        }

        $this->inWishList = $this->getWishListItems->isPuzzleInWishList($loggedPlayer->playerId, $this->puzzleId);

        return $this->inWishList;
    }

    public function isPuzzleInSellSwapList(): bool
    {
        if ($this->inSellSwapList !== null) {
            return $this->inSellSwapList;
        }

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        if ($loggedPlayer === null) {
            $this->inSellSwapList = false;
            return false;
        }

        $this->inSellSwapList = $this->getSellSwapListItems->isPuzzleInSellSwapList($loggedPlayer->playerId, $this->puzzleId);

        return $this->inSellSwapList;
    }

    public function isPuzzleLent(): bool
    {
        if ($this->inLentList !== null) {
            return $this->inLentList;
        }

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        if ($loggedPlayer === null) {
            $this->inLentList = false;
            return false;
        }

        $this->inLentList = $this->getLentPuzzles->isPuzzleLentByOwner($loggedPlayer->playerId, $this->puzzleId);

        return $this->inLentList;
    }

    public function isPuzzleBorrowed(): bool
    {
        if ($this->inBorrowedList !== null) {
            return $this->inBorrowedList;
        }

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        if ($loggedPlayer === null) {
            $this->inBorrowedList = false;
            return false;
        }

        $this->inBorrowedList = $this->getBorrowedPuzzles->isPuzzleBorrowedByHolder($loggedPlayer->playerId, $this->puzzleId);

        return $this->inBorrowedList;
    }
}
