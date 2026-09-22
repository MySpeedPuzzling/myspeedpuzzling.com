<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Results\MultiscanEligibilityReport;
use SpeedPuzzling\Web\Results\UserPuzzleStatuses;
use SpeedPuzzling\Web\Value\MultiscanAction;

/**
 * Which puzzles of a multiscan tray a batch action applies to, and why the
 * others are skipped. One implementation for the tray (counts on the buttons,
 * reasons under the rows) and for the batch handlers (which refuse anything
 * not eligible) - the numbers the user sees are the numbers the handler enforces.
 *
 * docs/features/multiscan/README.md §4
 */
readonly final class MultiscanEligibility
{
    /**
     * @param list<string> $puzzleIds
     */
    public function check(
        MultiscanAction $action,
        array $puzzleIds,
        UserPuzzleStatuses $statuses,
        null|string $collectionId = null,
    ): MultiscanEligibilityReport {
        $eligible = [];
        $skipped = [];
        $names = [];
        $lentIds = [];

        foreach (array_values(array_unique($puzzleIds)) as $puzzleId) {
            // A lent_puzzle row IS an open lend (a return deletes the row); the holder may have no name
            $lentTo = $statuses->lentToNames[$puzzleId] ?? null;
            $borrowedFrom = $statuses->borrowedFromNames[$puzzleId] ?? null;
            $isLent = isset($statuses->lentPuzzleIds[$puzzleId]);
            $isBorrowed = isset($statuses->borrowedPuzzleIds[$puzzleId]);

            $reason = match ($action) {
                MultiscanAction::AddToLibrary => isset($statuses->puzzleCollections[$puzzleId][$collectionId ?? Collection::SYSTEM_ID])
                    ? 'already_in_collection'
                    : null,
                MultiscanAction::AddToWishlist => match (true) {
                    in_array($puzzleId, $statuses->collection, true) => 'already_in_library',
                    in_array($puzzleId, $statuses->wishlist, true) => 'already_on_wishlist',
                    default => null,
                },
                MultiscanAction::Lend => $isLent ? 'already_lent' : null,
                MultiscanAction::Borrow => $isBorrowed ? 'already_borrowed' : null,
                MultiscanAction::Return => ($isLent || $isBorrowed) ? null : 'not_lent',
            };

            if ($isLent && $lentTo !== null) {
                $names[$puzzleId] = $lentTo;
            } elseif ($isBorrowed && $borrowedFrom !== null) {
                $names[$puzzleId] = $borrowedFrom;
            }

            if ($reason !== null) {
                $skipped[$puzzleId] = $reason;
                continue;
            }

            $eligible[] = $puzzleId;

            if ($action === MultiscanAction::Return) {
                $lentIds[$puzzleId] = $statuses->lentPuzzleIds[$puzzleId] ?? $statuses->borrowedPuzzleIds[$puzzleId];
            }
        }

        return new MultiscanEligibilityReport($eligible, $skipped, $names, $lentIds);
    }
}
