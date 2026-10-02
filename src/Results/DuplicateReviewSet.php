<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\DuplicateKind;
use SpeedPuzzling\Web\Value\DuplicateTier;

/**
 * The viewer's open cases that share results, shown as one card: every copy of the result, oldest first
 * (docs/features/duplicate-results.md, "Review page"). A result saved by three members of a team is one set of
 * three copies, not three cases. Tier and kind are those of its strongest case.
 */
readonly final class DuplicateReviewSet
{
    /**
     * @param non-empty-list<string> $caseIds the viewer's open cases of the set, the strongest first
     * @param non-empty-list<DuplicateReviewCopy> $copies oldest first
     */
    public function __construct(
        public array $caseIds,
        public DuplicateTier $tier,
        public DuplicateKind $kind,
        public FirstTryPuzzle $puzzle,
        public array $copies,
    ) {
    }

    /**
     * The case the actions are posted to - the handlers find the rest of the set from it.
     */
    public function caseId(): string
    {
        return $this->caseIds[0];
    }

    /**
     * Tier A/B: "most likely saved twice", the oldest copy preselected. Tier C is asked neutrally.
     */
    public function isLikely(): bool
    {
        return $this->tier !== DuplicateTier::Possible;
    }

    public function oldest(): DuplicateReviewCopy
    {
        return $this->copies[0];
    }

    public function sameTime(): bool
    {
        foreach ($this->copies as $copy) {
            if ($copy->secondsToSolve !== $this->oldest()->secondsToSolve) {
                return false;
            }
        }

        return true;
    }

    public function involves(string $timeId): bool
    {
        foreach ($this->copies as $copy) {
            if ($copy->timeId === strtolower($timeId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function timeIds(): array
    {
        return array_map(static fn (DuplicateReviewCopy $copy): string => $copy->timeId, $this->copies);
    }

    /**
     * How many copies the player may delete - decides which actions the card offers.
     */
    public function countTrackedBy(string $playerId): int
    {
        return count(array_filter(
            $this->copies,
            static fn (DuplicateReviewCopy $copy): bool => $copy->isTrackedBy($playerId),
        ));
    }

    /**
     * "Delete my copy" keeps the oldest of the other copies - the player's copy is the only one they may delete.
     */
    public function keptInsteadOf(DuplicateReviewCopy $deleted): DuplicateReviewCopy
    {
        foreach ($this->copies as $copy) {
            if ($copy->timeId !== $deleted->timeId) {
                return $copy;
            }
        }

        return $deleted;
    }
}
