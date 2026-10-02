<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DuplicateResults;

use SpeedPuzzling\Web\Results\DuplicateCandidate;
use SpeedPuzzling\Web\Value\DuplicateClassification;
use SpeedPuzzling\Web\Value\DuplicateKind;
use SpeedPuzzling\Web\Value\DuplicateTier;

/**
 * The tier rules of docs/features/duplicate-results.md ("Definitions"), judged per person.
 * Pure: everything it needs travels in the candidate (GetDuplicateCandidates).
 */
final class DuplicateClassifier
{
    // A re-sent form: below this a person cannot have typed the result again (fastest real pair: 6 s)
    public const int CERTAIN_MAX_GAP_SECONDS = 10;

    // Different days but saved this close together - mostly a wrong date
    public const int SAVED_WITHIN_SECONDS = 3600;

    public function classify(DuplicateCandidate $candidate): null|DuplicateClassification
    {
        $older = $candidate->older;
        $newer = $candidate->newer;

        if (!$candidate->sameTracker()) {
            // Two members of a pair/team each saved it (same or overlapping people) - on any day
            if ($older->isGroup() && $newer->isGroup()) {
                return new DuplicateClassification(DuplicateTier::Strong, DuplicateKind::TeammateCopy);
            }

            // Different trackers and not both groups: the person's own solo result + a group tracked by somebody else
            return $this->soloAndGroup($candidate);
        }

        if (!$older->isGroup() && !$newer->isGroup()) {
            if (!$candidate->sameDay()) {
                return $this->savedWithinHour($candidate);
            }

            // Short puzzles solved repeatedly - that is where genuine near-identical times live
            if ($candidate->practiceSession) {
                return new DuplicateClassification(DuplicateTier::Possible, DuplicateKind::SameTracker);
            }

            return new DuplicateClassification(
                $this->isCertain($candidate) ? DuplicateTier::Certain : DuplicateTier::Strong,
                DuplicateKind::SameTracker,
            );
        }

        if ($older->isGroup() && $newer->isGroup()) {
            if ($older->teamId === $newer->teamId) {
                if (!$candidate->sameDay()) {
                    return new DuplicateClassification(DuplicateTier::Possible, DuplicateKind::SameTrackerGroup);
                }

                return new DuplicateClassification(
                    $this->isCertain($candidate) ? DuplicateTier::Certain : DuplicateTier::Strong,
                    DuplicateKind::SameTrackerGroup,
                );
            }

            // The same tracker saved it with two different groups the person is in (e.g. a guest added later)
            if ($candidate->sameDay()) {
                return new DuplicateClassification(DuplicateTier::Strong, DuplicateKind::SameTrackerGroup);
            }

            return $this->savedWithinHour($candidate);
        }

        return $this->soloAndGroup($candidate);
    }

    /**
     * Same tracker, identical in every field, saved ≤ 10 s apart, no other same-day result of the puzzle by the
     * person and nothing else saved in between - the same form sent again.
     */
    private function isCertain(DuplicateCandidate $candidate): bool
    {
        return $candidate->sameTracker()
            && $candidate->sameDay()
            && $candidate->gapSeconds() <= self::CERTAIN_MAX_GAP_SECONDS
            && $candidate->differences() === []
            && $candidate->practiceSession === false
            && $candidate->savedInBetween === false;
    }

    private function soloAndGroup(DuplicateCandidate $candidate): null|DuplicateClassification
    {
        if ($candidate->sameDay()) {
            return new DuplicateClassification(DuplicateTier::Strong, DuplicateKind::SoloAndGroup);
        }

        return $this->savedWithinHour($candidate);
    }

    private function savedWithinHour(DuplicateCandidate $candidate): null|DuplicateClassification
    {
        if ($candidate->gapSeconds() < self::SAVED_WITHIN_SECONDS) {
            return new DuplicateClassification(DuplicateTier::Possible, DuplicateKind::SavedWithinHour);
        }

        // Solo on different days, saved later: chance explains most of them - never flagged afterwards
        return null;
    }
}
