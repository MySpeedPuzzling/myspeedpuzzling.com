<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\FirstTry;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetFirstTryTimes;
use SpeedPuzzling\Web\Results\FirstTryTime;
use SpeedPuzzling\Web\Services\DuplicateResults\DuplicateAssessor;
use SpeedPuzzling\Web\Value\FirstTryAssessment;
use SpeedPuzzling\Web\Value\FirstTryEntry;
use SpeedPuzzling\Web\Value\FirstTryNoticeLine;
use SpeedPuzzling\Web\Value\ResultEntryCheck;

/**
 * The one place the first-try rules are decided - the add/edit forms, the live check, the handlers and the
 * API all ask here (docs/features/first-try-integrity.md). Costs one query. The forms' "same time already saved"
 * check is decided from the same rows (check(), docs/features/duplicate-results.md).
 */
readonly final class FirstTryAssessor
{
    public function __construct(
        private GetFirstTryTimes $getFirstTryTimes,
        private ClockInterface $clock,
        private DuplicateAssessor $duplicateAssessor,
    ) {
    }

    public function assess(FirstTryEntry $entry): FirstTryAssessment
    {
        [$times, $members] = $this->visibleTimes($entry);

        return $this->firstTry($entry, $times, $members);
    }

    /**
     * The first-try rules (only when the tag is ticked) and the "same time already saved" check (only with a time,
     * docs/features/duplicate-results.md) from the same rows - the forms pay one query for both, none for neither.
     */
    public function check(FirstTryEntry $entry, bool $firstAttempt): ResultEntryCheck
    {
        if ($firstAttempt === false && $entry->secondsToSolve === null) {
            return ResultEntryCheck::nothing();
        }

        [$times, $members] = $this->visibleTimes($entry);

        // The form sent again after its result was saved - the viewer's own result, so never filtered out. Only the
        // same entry (time and day; the puzzle is the query's): a changed one is a new result and is checked
        $solvedDay = ($entry->solvedAt ?? $this->clock->now())->format('Y-m-d');

        foreach ($times as $time) {
            if (
                $entry->newTimeId !== null
                && $time->timeId === strtolower($entry->newTimeId)
                && $time->secondsToSolve === $entry->secondsToSolve
                && $time->solvedDay() === $solvedDay
            ) {
                return ResultEntryCheck::nothing();
            }
        }

        return new ResultEntryCheck(
            firstTry: $firstAttempt ? $this->firstTry($entry, $times, $members) : null,
            duplicates: $entry->secondsToSolve !== null ? $this->duplicateAssessor->assess(
                entry: $entry,
                secondsToSolve: $entry->secondsToSolve,
                times: $times,
                members: $members,
                solvedAt: $entry->solvedAt ?? $this->clock->now(),
                now: $this->clock->now(),
            ) : null,
        );
    }

    /**
     * @return array{list<FirstTryTime>, list<string>} the results the viewer may be told about, and the people of
     *                                                 the new result who are not hidden from the viewer
     */
    private function visibleTimes(FirstTryEntry $entry): array
    {
        $members = array_values(array_unique(array_map('strtolower', $entry->memberPlayerIds)));
        $viewer = strtolower($entry->actorPlayerId);

        $times = $this->getFirstTryTimes->ofPlayersOnPuzzle($entry->puzzleId, $members, $entry->editedTimeId);

        // A teammate hidden from the viewer (private without the viewer on their allow list, or blocked) is left
        // out completely: nothing about them may reach the viewer, not even a refusal. Their own conflicts page
        // shows them whatever duplicate this save creates
        $visibleMembers = $this->visibleMembers($times, $members, $viewer);
        $times = array_values(array_filter(
            $times,
            static function (FirstTryTime $time) use ($visibleMembers): bool {
                foreach ($visibleMembers as $member) {
                    if ($time->involves($member)) {
                        return true;
                    }
                }

                return false;
            },
        ));

        return [$times, $visibleMembers];
    }

    /**
     * @param list<FirstTryTime> $times
     * @param list<string> $members
     */
    private function firstTry(FirstTryEntry $entry, array $times, array $members): FirstTryAssessment
    {
        $solvedAt = $entry->solvedAt ?? $this->clock->now();
        $viewer = strtolower($entry->actorPlayerId);

        $holds = array_values(array_filter($times, static fn(FirstTryTime $time): bool => $time->firstAttempt));
        $viewerCanMove = $holds !== [];

        foreach ($holds as $hold) {
            if ($hold->involves($viewer) === false) {
                $viewerCanMove = false;
            }
        }

        return new FirstTryAssessment(
            holds: $holds,
            holdLines: $this->holdLines($holds, $members, $viewer),
            earlierLines: $this->earlierLines($times, $members, $viewer, $solvedAt),
            viewerCanMove: $viewerCanMove,
            tolerated: $this->isTolerated($entry, $members),
            solvedAt: $solvedAt,
        );
    }

    /**
     * @param list<FirstTryTime> $times
     * @param list<string> $members
     * @return list<string>
     */
    private function visibleMembers(array $times, array $members, string $viewer): array
    {
        $hidden = [];

        foreach ($times as $time) {
            foreach ($time->people as $person) {
                $id = $person->playerId !== null ? strtolower($person->playerId) : null;

                if ($id !== null && $id !== $viewer && $person->masked) {
                    $hidden[$id] = true;
                }
            }
        }

        return array_values(array_filter($members, static fn(string $member): bool => isset($hidden[$member]) === false));
    }

    /**
     * @param list<string> $members
     */
    private function isTolerated(FirstTryEntry $entry, array $members): bool
    {
        if ($entry->editedTimeId === null || $entry->previouslyFirstAttempt === false || $entry->previousMemberPlayerIds === null) {
            return false;
        }

        $before = array_map('strtolower', $entry->previousMemberPlayerIds);

        return array_diff($members, $before) === [];
    }

    /**
     * @param list<FirstTryTime> $holds
     * @param list<string> $members
     * @return list<FirstTryNoticeLine>
     */
    private function holdLines(array $holds, array $members, string $viewer): array
    {
        $lines = [];
        $named = [];

        foreach ($holds as $hold) {
            if ($hold->involves($viewer)) {
                $lines[] = new FirstTryNoticeLine(
                    kind: FirstTryNoticeLine::OWN,
                    date: $hold->solvedAt,
                    time: $hold,
                    with: $this->labelsOf($hold, $viewer),
                );

                continue;
            }

            foreach ($hold->people as $person) {
                if ($person->playerId === null || in_array(strtolower($person->playerId), $members, true) === false) {
                    continue;
                }

                // One line per teammate: their first try is the point, not how many of them there are
                if (isset($named[strtolower($person->playerId)])) {
                    continue;
                }

                $named[strtolower($person->playerId)] = true;
                $lines[] = new FirstTryNoticeLine(
                    kind: FirstTryNoticeLine::TEAMMATE,
                    date: $hold->solvedAt,
                    time: $hold,
                    person: $person,
                );
            }
        }

        return $lines;
    }

    /**
     * The earliest solve from a day before the new result, per person of the result. Marked results are left
     * out - those are holds.
     *
     * @param list<FirstTryTime> $times oldest first
     * @param list<string> $members
     * @return list<FirstTryNoticeLine>
     */
    private function earlierLines(array $times, array $members, string $viewer, DateTimeImmutable $solvedAt): array
    {
        $day = $solvedAt->format('Y-m-d');
        $seen = [];
        $lines = [];

        foreach ($times as $time) {
            if ($time->firstAttempt || $time->solvedDay() >= $day) {
                continue;
            }

            foreach ($time->people as $person) {
                $id = $person->playerId !== null ? strtolower($person->playerId) : null;

                if ($id === null || in_array($id, $members, true) === false || isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;

                if ($id === $viewer) {
                    $lines[] = new FirstTryNoticeLine(kind: FirstTryNoticeLine::OWN, date: $time->solvedAt, time: $time);
                } else {
                    $lines[] = new FirstTryNoticeLine(kind: FirstTryNoticeLine::TEAMMATE, date: $time->solvedAt, time: $time, person: $person);
                }
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function labelsOf(FirstTryTime $time, string $viewer): array
    {
        $labels = [];

        foreach ($time->peopleExcept($viewer) as $person) {
            $labels[] = $person->label() ?? '';
        }

        return $labels;
    }
}
