<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Api;

use SpeedPuzzling\Web\Api\V1\LibraryCollectionResponse;
use SpeedPuzzling\Web\Api\V1\LibraryLendBorrowSectionResponse;
use SpeedPuzzling\Web\Api\V1\LibraryResponse;
use SpeedPuzzling\Web\Api\V1\LibrarySectionResponse;
use SpeedPuzzling\Web\Api\V1\LibrarySellSwapSectionResponse;
use SpeedPuzzling\Web\Query\GetBorrowedPuzzles;
use SpeedPuzzling\Web\Query\GetLentPuzzles;
use SpeedPuzzling\Web\Query\GetPlayerCollectionsWithCounts;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Query\GetUnsolvedPuzzles;
use SpeedPuzzling\Web\Query\GetWishListItems;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Value\CollectionVisibility;

/**
 * The puzzle library page (PuzzleLibraryController) as one API response: the
 * counts of every section, computed by the same count queries the page runs,
 * under the page's visibility rules (PuzzleLibraryVisibility). A section the
 * token may not see costs no query and reports count 0 with its visibility;
 * the collections list holds the system collection ("default", the owner's
 * puzzle-collection visibility) when it is visible and the custom collections
 * the token may see (all of them for the owner, public ones otherwise).
 */
final readonly class PuzzleLibrarySummaryFactory
{
    /** The name GET /me/collections gives the system collection. */
    private const string SYSTEM_COLLECTION_NAME = 'Default Collection';

    public function __construct(
        private PuzzleLibraryVisibility $visibility,
        private GetPlayerCollectionsWithCounts $getPlayerCollectionsWithCounts,
        private GetUnsolvedPuzzles $getUnsolvedPuzzles,
        private GetBorrowedPuzzles $getBorrowedPuzzles,
        private GetWishListItems $getWishListItems,
        private GetLentPuzzles $getLentPuzzles,
        private GetSellSwapListItems $getSellSwapListItems,
        private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
    ) {
    }

    public function summary(PlayerProfile $owner): LibraryResponse
    {
        $playerId = $owner->playerId;

        $lentCount = 0;
        $borrowedCount = 0;

        if ($this->visibility->isVisibleToTokenOwner($owner, $owner->lendBorrowListVisibility)) {
            $lentCount = $this->getLentPuzzles->countByOwnerId($playerId);
            $borrowedCount = $this->getBorrowedPuzzles->countByHolderId($playerId);
        }

        return new LibraryResponse(
            playerId: $playerId,
            collections: $this->collections($owner),
            unsolved: new LibrarySectionResponse(
                count: $this->visibility->isVisibleToTokenOwner($owner, $owner->unsolvedPuzzlesVisibility)
                    ? $this->getUnsolvedPuzzles->countByPlayerId($playerId) + $this->getBorrowedPuzzles->countUnsolvedByHolderId($playerId)
                    : 0,
                visibility: $this->visibility->reportedVisibility($owner, $owner->unsolvedPuzzlesVisibility),
            ),
            wishlist: new LibrarySectionResponse(
                count: $this->visibility->isVisibleToTokenOwner($owner, $owner->wishListVisibility)
                    ? $this->getWishListItems->countByPlayerId($playerId)
                    : 0,
                visibility: $this->visibility->reportedVisibility($owner, $owner->wishListVisibility),
            ),
            lendBorrow: new LibraryLendBorrowSectionResponse(
                lentCount: $lentCount,
                borrowedCount: $borrowedCount,
                visibility: $this->visibility->reportedVisibility($owner, $owner->lendBorrowListVisibility),
            ),
            // always public on the website - only a private profile hides it
            sellSwap: new LibrarySellSwapSectionResponse(
                count: $this->visibility->isVisibleToTokenOwner($owner, CollectionVisibility::Public)
                    ? $this->getSellSwapListItems->countByPlayerId($playerId)
                    : 0,
            ),
            solved: new LibrarySectionResponse(
                count: $this->visibility->isVisibleToTokenOwner($owner, $owner->solvedPuzzlesVisibility)
                    ? $this->getPlayerSolvedPuzzles->countByPlayerId($playerId)
                    : 0,
                visibility: $this->visibility->reportedVisibility($owner, $owner->solvedPuzzlesVisibility),
            ),
        );
    }

    /**
     * @return list<LibraryCollectionResponse>
     */
    private function collections(PlayerProfile $owner): array
    {
        // a private profile shows no collections at all to anybody but the owner
        if ($this->visibility->isVisibleToTokenOwner($owner, CollectionVisibility::Public) === false) {
            return [];
        }

        $isOwner = $this->visibility->isOwnedByTokenOwner($owner);
        $collections = [];

        if ($this->visibility->isVisibleToTokenOwner($owner, $owner->puzzleCollectionVisibility)) {
            $collections[] = new LibraryCollectionResponse(
                collectionId: 'default',
                name: self::SYSTEM_COLLECTION_NAME,
                description: null,
                visibility: $owner->puzzleCollectionVisibility->value,
                itemCount: $this->getPlayerCollectionsWithCounts->countSystemCollection($owner->playerId),
            );
        }

        foreach ($this->getPlayerCollectionsWithCounts->byPlayerId($owner->playerId, includePrivate: $isOwner) as $collection) {
            $collections[] = new LibraryCollectionResponse(
                collectionId: $collection->collectionId ?? 'default',
                name: $collection->name,
                description: $collection->description,
                visibility: $collection->visibility->value,
                itemCount: $collection->itemCount,
            );
        }

        return $collections;
    }
}
