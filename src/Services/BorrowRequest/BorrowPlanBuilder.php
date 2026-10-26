<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\BorrowRequest;

use SpeedPuzzling\Web\Results\BorrowCandidate;
use SpeedPuzzling\Web\Results\BorrowPlan;

/**
 * "Ask the fewest people for the most puzzles" (docs/features/wishlist-availability.md): a greedy cover. First whoever
 * can lend the most of the puzzles, then whoever adds the most of the rest, until nobody adds anything - so a puzzle is
 * never asked of two people. Ties go to whoever owns more of the puzzles in total, then by name. A lent-out puzzle is
 * never planned. Also the default owner of each ticked puzzle on the ask sheet.
 */
final readonly class BorrowPlanBuilder
{
    /**
     * @param list<BorrowCandidate> $candidates
     * @param null|list<string> $puzzleIds only these puzzles (the ticked ones); null = every puzzle of the candidates
     */
    public function plan(array $candidates, null|array $puzzleIds = null): BorrowPlan
    {
        $wanted = $puzzleIds !== null ? array_fill_keys($puzzleIds, true) : null;

        /** @var array<string, array<string, true>> $ownedBy owner id => puzzle ids they can lend */
        $ownedBy = [];
        /** @var array<string, string> $names */
        $names = [];
        /** @var array<string, true> $puzzles */
        $puzzles = $wanted ?? [];

        foreach ($candidates as $candidate) {
            if ($wanted !== null && isset($wanted[$candidate->puzzleId]) === false) {
                continue;
            }

            $puzzles[$candidate->puzzleId] = true;

            if ($candidate->lentOut) {
                continue;
            }

            $ownedBy[$candidate->ownerId][$candidate->puzzleId] = true;
            $names[$candidate->ownerId] = mb_strtolower($candidate->ownerDisplayName());
        }

        $remaining = [];

        foreach ($ownedBy as $owned) {
            $remaining += $owned;
        }

        $plan = [];

        while ($remaining !== [] && $ownedBy !== []) {
            $best = null;
            $bestCovers = [];

            foreach ($ownedBy as $ownerId => $owned) {
                $covers = array_intersect_key($owned, $remaining);

                if ($best === null || $this->isBetter($ownerId, $covers, $owned, $best, $bestCovers, $ownedBy[$best], $names)) {
                    $best = (string) $ownerId;
                    $bestCovers = $covers;
                }
            }

            if ($bestCovers === []) {
                break;
            }

            $plan[$best] = array_map(strval(...), array_keys($bestCovers));
            $remaining = array_diff_key($remaining, $bestCovers);
            unset($ownedBy[$best]);
        }

        $assigned = [];

        foreach ($plan as $puzzleIdsOfOwner) {
            $assigned += array_fill_keys($puzzleIdsOfOwner, true);
        }

        return new BorrowPlan(
            puzzlesByOwner: $plan,
            unassigned: array_map(strval(...), array_keys(array_diff_key($puzzles, $assigned))),
        );
    }

    /**
     * @param array<string, true> $covers
     * @param array<string, true> $owned
     * @param array<string, true> $bestCovers
     * @param array<string, true> $bestOwned
     * @param array<string, string> $names
     */
    private function isBetter(
        string $ownerId,
        array $covers,
        array $owned,
        string $best,
        array $bestCovers,
        array $bestOwned,
        array $names,
    ): bool {
        if (count($covers) !== count($bestCovers)) {
            return count($covers) > count($bestCovers);
        }

        if (count($owned) !== count($bestOwned)) {
            return count($owned) > count($bestOwned);
        }

        $byName = strcmp($names[$ownerId] ?? '', $names[$best] ?? '');

        return $byName !== 0 ? $byName < 0 : strcmp($ownerId, $best) < 0;
    }
}
