<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Api;

use SpeedPuzzling\Web\Api\V1\LentPuzzleCounterpartyResponse;
use SpeedPuzzling\Web\Api\V1\LentPuzzleResponse;
use SpeedPuzzling\Web\Api\V1\SellSwapItemResponse;
use SpeedPuzzling\Web\Api\V1\UnsolvedPuzzleResponse;
use SpeedPuzzling\Web\Api\V1\WishlistItemResponse;
use SpeedPuzzling\Web\Query\GetBorrowedPuzzles;
use SpeedPuzzling\Web\Query\GetLentPuzzles;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Query\GetUnsolvedPuzzles;
use SpeedPuzzling\Web\Query\GetWishListItems;
use SpeedPuzzling\Web\Results\BorrowedPuzzleOverview;
use SpeedPuzzling\Web\Results\LentPuzzleOverview;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Results\SellSwapListItemOverview;
use SpeedPuzzling\Web\Results\UnsolvedPuzzleItem;
use SpeedPuzzling\Web\Results\WishListItemOverview;

/**
 * The items of a player's puzzle-library lists for the public API - wishlist,
 * unsolved puzzles, lend/borrow list, sell/swap list - built from the very
 * queries the website's list pages use, so the two can never show different
 * puzzles, and carrying the same four insight objects as a collection item.
 *
 * Who may see a list is decided by the providers (PuzzleLibraryVisibility);
 * this class is only ever called for a list the token may see. Whatever the
 * list size, the insight objects cost one PuzzleResponseFactory::insightsFor()
 * batch: "solves" are the list owner's own history (results:read data),
 * "prediction" is always the token owner's own forecast - on /me the two are
 * the same player; on /players/{id} the prediction is what the website shows
 * a visitor next to somebody else's list, never the owner's (plan §0 N1).
 */
final readonly class PuzzleLibraryItemsFactory
{
    public function __construct(
        private GetWishListItems $getWishListItems,
        private GetUnsolvedPuzzles $getUnsolvedPuzzles,
        private GetBorrowedPuzzles $getBorrowedPuzzles,
        private GetLentPuzzles $getLentPuzzles,
        private GetSellSwapListItems $getSellSwapListItems,
        private PuzzleResponseFactory $puzzleResponseFactory,
    ) {
    }

    /**
     * @return list<WishlistItemResponse> newest first
     */
    public function wishlist(string $ownerPlayerId): array
    {
        $items = $this->getWishListItems->byPlayerId($ownerPlayerId);

        $insights = $this->insightsFor(
            array_map(static fn (WishListItemOverview $item): string => $item->puzzleId, $items),
            $ownerPlayerId,
        );

        return array_values(array_map(
            static fn (WishListItemOverview $item): WishlistItemResponse => new WishlistItemResponse(
                wishlistItemId: $item->wishListItemId,
                puzzleId: $item->puzzleId,
                puzzleName: $item->puzzleName,
                manufacturerName: $item->manufacturerName,
                piecesCount: $item->piecesCount,
                image: $item->image,
                addedAt: $item->addedAt->format('c'),
                statistics: $insights->statistics($item->puzzleId),
                difficulty: $insights->difficulty($item->puzzleId),
                prediction: $insights->prediction($item->puzzleId),
                solves: $insights->solves($item->puzzleId),
            ),
            $items,
        ));
    }

    /**
     * Borrowed unsolved puzzles first, then the unsolved puzzles of the
     * player's collections - the order of the website's page.
     *
     * @return list<UnsolvedPuzzleResponse>
     */
    public function unsolvedPuzzles(string $ownerPlayerId): array
    {
        $items = [
            ...$this->getBorrowedPuzzles->unsolvedByHolderId($ownerPlayerId),
            ...$this->getUnsolvedPuzzles->byPlayerId($ownerPlayerId),
        ];

        $insights = $this->insightsFor(
            array_map(static fn (UnsolvedPuzzleItem $item): string => $item->puzzleId, $items),
            $ownerPlayerId,
        );

        return array_values(array_map(
            static fn (UnsolvedPuzzleItem $item): UnsolvedPuzzleResponse => new UnsolvedPuzzleResponse(
                puzzleId: $item->puzzleId,
                puzzleName: $item->puzzleName,
                manufacturerName: $item->manufacturerName,
                piecesCount: $item->piecesCount,
                image: $item->image,
                addedAt: $item->addedAt->format('c'),
                isBorrowed: $item->isBorrowed,
                statistics: $insights->statistics($item->puzzleId),
                difficulty: $insights->difficulty($item->puzzleId),
                prediction: $insights->prediction($item->puzzleId),
                solves: $insights->solves($item->puzzleId),
            ),
            $items,
        ));
    }

    /**
     * The puzzles the player lent out, then the ones they are borrowing - the
     * two tabs of the website's page, each newest first.
     *
     * @return list<LentPuzzleResponse>
     */
    public function lendBorrow(string $ownerPlayerId): array
    {
        $lent = $this->getLentPuzzles->byOwnerId($ownerPlayerId);
        $borrowed = $this->getBorrowedPuzzles->byHolderId($ownerPlayerId);

        $insights = $this->insightsFor(
            [
                ...array_map(static fn (LentPuzzleOverview $item): string => $item->puzzleId, $lent),
                ...array_map(static fn (BorrowedPuzzleOverview $item): string => $item->puzzleId, $borrowed),
            ],
            $ownerPlayerId,
        );

        $items = [];

        foreach ($lent as $item) {
            $items[] = new LentPuzzleResponse(
                lentPuzzleId: $item->lentPuzzleId,
                direction: LentPuzzleResponse::DIRECTION_LENT,
                puzzleId: $item->puzzleId,
                puzzleName: $item->puzzleName,
                manufacturerName: $item->manufacturerName,
                piecesCount: $item->piecesCount,
                image: $item->image,
                counterparty: new LentPuzzleCounterpartyResponse(
                    playerId: $item->currentHolderId,
                    name: $item->currentHolderName,
                ),
                lentAt: $item->lentAt->format('c'),
                notes: $item->notes,
                statistics: $insights->statistics($item->puzzleId),
                difficulty: $insights->difficulty($item->puzzleId),
                prediction: $insights->prediction($item->puzzleId),
                solves: $insights->solves($item->puzzleId),
            );
        }

        foreach ($borrowed as $item) {
            $items[] = new LentPuzzleResponse(
                lentPuzzleId: $item->lentPuzzleId,
                direction: LentPuzzleResponse::DIRECTION_BORROWED,
                puzzleId: $item->puzzleId,
                puzzleName: $item->puzzleName,
                manufacturerName: $item->manufacturerName,
                piecesCount: $item->piecesCount,
                image: $item->image,
                counterparty: new LentPuzzleCounterpartyResponse(
                    playerId: $item->ownerId,
                    name: $item->ownerName,
                ),
                lentAt: $item->lentAt->format('c'),
                notes: $item->notes,
                statistics: $insights->statistics($item->puzzleId),
                difficulty: $insights->difficulty($item->puzzleId),
                prediction: $insights->prediction($item->puzzleId),
                solves: $insights->solves($item->puzzleId),
            );
        }

        return $items;
    }

    /**
     * @return list<SellSwapItemResponse> newest first
     */
    public function sellSwap(PlayerProfile $owner): array
    {
        $items = $this->getSellSwapListItems->byPlayerId($owner->playerId);

        $insights = $this->insightsFor(
            array_map(static fn (SellSwapListItemOverview $item): string => $item->puzzleId, $items),
            $owner->playerId,
        );

        // The seller's list-wide currency, as the website prints it next to every
        // price: the custom one, or the chosen ISO code unless that says "custom"
        $settings = $owner->sellSwapListSettings;
        $currency = $settings === null
            ? null
            : ($settings->customCurrency ?? ($settings->currency !== 'custom' ? $settings->currency : null));

        return array_values(array_map(
            static fn (SellSwapListItemOverview $item): SellSwapItemResponse => new SellSwapItemResponse(
                itemId: $item->sellSwapListItemId,
                puzzleId: $item->puzzleId,
                puzzleName: $item->puzzleName,
                manufacturerName: $item->manufacturerName,
                piecesCount: $item->piecesCount,
                image: $item->image,
                listingType: $item->listingType->value,
                price: $item->price,
                currency: $item->price !== null ? $currency : null,
                condition: $item->condition->value,
                comment: $item->comment,
                isReserved: $item->reserved,
                isPublishedOnMarketplace: $item->publishedOnMarketplace,
                addedAt: $item->addedAt->format('c'),
                statistics: $insights->statistics($item->puzzleId),
                difficulty: $insights->difficulty($item->puzzleId),
                prediction: $insights->prediction($item->puzzleId),
                solves: $insights->solves($item->puzzleId),
            ),
            $items,
        ));
    }

    /**
     * One batch per list, whatever its size (an empty list costs nothing).
     *
     * @param array<string> $puzzleIds
     */
    private function insightsFor(array $puzzleIds, string $ownerPlayerId): PuzzleInsightsBatch
    {
        return $this->puzzleResponseFactory->insightsFor(
            $puzzleIds,
            solvesOfPlayerId: $ownerPlayerId,
            includePrediction: true,
        );
    }
}
