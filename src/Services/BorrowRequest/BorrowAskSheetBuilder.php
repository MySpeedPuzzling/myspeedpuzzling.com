<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\BorrowRequest;

use SpeedPuzzling\Web\Message\AskToBorrowPuzzles;
use SpeedPuzzling\Web\Query\GetBorrowCandidates;
use SpeedPuzzling\Web\Query\GetBorrowRequests;
use SpeedPuzzling\Web\Query\GetWishListItems;
use SpeedPuzzling\Web\Results\BorrowAskSheet;
use SpeedPuzzling\Web\Results\BorrowCandidate;
use SpeedPuzzling\Web\Results\UserPuzzleStatuses;
use SpeedPuzzling\Web\Results\WishListItemOverview;
use SpeedPuzzling\Web\Value\BorrowAskSkipReason;

/**
 * What the ask-to-borrow sheet offers for the puzzles picked on the wishlist (docs/features/wishlist-availability.md):
 * each puzzle goes to the owner the suggested plan picks (BorrowPlanBuilder), and the sheet lets the asker move it to
 * another owner of it or leave it out. Only wishlist puzzles; at most AskToBorrowPuzzles::MAX_PUZZLES of them.
 * Three statements: the wishlist, the candidates, the asker's open asks.
 */
readonly final class BorrowAskSheetBuilder
{
    public function __construct(
        private GetWishListItems $getWishListItems,
        private GetBorrowCandidates $getBorrowCandidates,
        private GetBorrowRequests $getBorrowRequests,
        private BorrowPlanBuilder $borrowPlanBuilder,
    ) {
    }

    /**
     * @param list<string> $pickedPuzzleIds
     */
    public function build(string $askerId, array $pickedPuzzleIds, UserPuzzleStatuses $askerStatuses): BorrowAskSheet
    {
        $wishlist = [];

        foreach ($this->getWishListItems->byPlayerId($askerId) as $item) {
            $wishlist[$item->puzzleId] = $item;
        }

        $picked = array_values(array_filter($pickedPuzzleIds, static fn (string $id): bool => isset($wishlist[$id])));
        $truncated = count($picked) > AskToBorrowPuzzles::MAX_PUZZLES;
        $picked = array_slice($picked, 0, AskToBorrowPuzzles::MAX_PUZZLES);

        $notToBorrow = array_fill_keys([...$askerStatuses->collection, ...$askerStatuses->borrowed], true);
        $asked = $this->getBorrowRequests->openOfAsker($askerId, $picked);
        $candidates = array_values(array_filter(
            $this->getBorrowCandidates->forPuzzles($askerId, $picked),
            static fn (BorrowCandidate $candidate): bool => isset($notToBorrow[$candidate->puzzleId]) === false,
        ));

        $owners = [];
        $ownersByPuzzle = [];
        $lentOutOnly = [];

        foreach ($candidates as $candidate) {
            $owners[$candidate->ownerId] ??= $candidate;

            if ($candidate->lentOut) {
                $lentOutOnly[$candidate->puzzleId] ??= true;

                continue;
            }

            $lentOutOnly[$candidate->puzzleId] = false;

            if (isset($asked[$candidate->puzzleId]) === false) {
                $ownersByPuzzle[$candidate->puzzleId][] = $candidate->ownerId;
            }
        }

        $plan = $this->borrowPlanBuilder->plan(
            array_values(array_filter($candidates, static fn (BorrowCandidate $candidate): bool => isset($asked[$candidate->puzzleId]) === false)),
            $picked,
        );

        // Every owner gets a group - the plan's first, in its order; the rest stay empty until a puzzle moves there
        $puzzlesByOwner = $plan->puzzlesByOwner;

        foreach ($ownersByPuzzle as $ownerIds) {
            foreach ($ownerIds as $ownerId) {
                $puzzlesByOwner[$ownerId] ??= [];
            }
        }

        $notAskable = [];

        foreach ($picked as $puzzleId) {
            if ($plan->ownerOf($puzzleId) !== null) {
                continue;
            }

            $notAskable[$puzzleId] = match (true) {
                isset($asked[$puzzleId]) => BorrowAskSkipReason::AlreadyAsked,
                ($lentOutOnly[$puzzleId] ?? false) === true => BorrowAskSkipReason::LentOut,
                default => BorrowAskSkipReason::Unavailable,
            };
        }

        return new BorrowAskSheet(
            owners: array_intersect_key($owners, $puzzlesByOwner),
            puzzlesByOwner: $puzzlesByOwner,
            ownersByPuzzle: $ownersByPuzzle,
            puzzles: array_filter($wishlist, static fn (WishListItemOverview $item): bool => in_array($item->puzzleId, $picked, true)),
            notAskable: $notAskable,
            truncated: $truncated,
        );
    }
}
