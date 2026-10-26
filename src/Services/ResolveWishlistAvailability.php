<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Query\GetBorrowCandidates;
use SpeedPuzzling\Web\Query\GetBorrowRequests;
use SpeedPuzzling\Web\Query\GetWishlistMarketplaceAvailability;
use SpeedPuzzling\Web\Results\BorrowCandidate;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Results\UserPuzzleStatuses;
use SpeedPuzzling\Web\Results\WishlistAvailability;
use SpeedPuzzling\Web\Services\BorrowRequest\BorrowPlanBuilder;

/**
 * The wishlist page's availability (docs/features/wishlist-availability.md). Anybody looking at a wishlist gets the
 * marketplace offers - one statement. Its owner also gets who of their favorites could lend each puzzle, their own open
 * asks and the suggested plan - two more. A puzzle already in the owner's collection, or borrowed by them, is not
 * offered for borrowing; neither is one they are already asking somebody for.
 */
readonly final class ResolveWishlistAvailability
{
    public function __construct(
        private GetWishlistMarketplaceAvailability $getWishlistMarketplaceAvailability,
        private GetBorrowCandidates $getBorrowCandidates,
        private GetBorrowRequests $getBorrowRequests,
        private BorrowPlanBuilder $borrowPlanBuilder,
    ) {
    }

    /**
     * @param list<string> $puzzleIds the wishlist's puzzles
     */
    public function forViewer(
        null|PlayerProfile $viewer,
        string $listOwnerId,
        array $puzzleIds,
        UserPuzzleStatuses $viewerStatuses,
    ): WishlistAvailability {
        $isOwnList = $viewer !== null && $viewer->playerId === $listOwnerId;
        $viewerCountry = $viewer?->country;

        if ($puzzleIds === []) {
            return new WishlistAvailability($isOwnList, $viewerCountry !== null);
        }

        $offers = $this->getWishlistMarketplaceAvailability->forPuzzles($puzzleIds, $viewer?->playerId, $viewerCountry);

        if ($isOwnList === false) {
            return new WishlistAvailability(false, $viewerCountry !== null, $offers);
        }

        $notToBorrow = array_fill_keys([...$viewerStatuses->collection, ...$viewerStatuses->borrowed], true);
        $owners = [];

        foreach ($this->getBorrowCandidates->forPuzzles($listOwnerId, $puzzleIds) as $candidate) {
            if (isset($notToBorrow[$candidate->puzzleId]) === false) {
                $owners[$candidate->puzzleId][] = $candidate;
            }
        }

        $asked = $this->getBorrowRequests->openOfAsker($listOwnerId, $puzzleIds);

        $askable = array_filter(
            array_merge(...array_values($owners)),
            static fn (BorrowCandidate $candidate): bool => isset($asked[$candidate->puzzleId]) === false,
        );
        $plan = $this->borrowPlanBuilder->plan(array_values($askable));

        return new WishlistAvailability(
            isOwnList: true,
            viewerHasCountry: $viewerCountry !== null,
            offers: $offers,
            owners: $owners,
            asked: $asked,
            suggestedPuzzleIds: $plan->assignedPuzzleIds(),
            suggestedPeople: count($plan->puzzlesByOwner),
        );
    }
}
