<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\FirstTry;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetFirstTryTimes;
use SpeedPuzzling\Web\Results\FirstTryTime;
use SpeedPuzzling\Web\Value\FirstTryAssessment;
use SpeedPuzzling\Web\Value\FirstTryEntry;
use SpeedPuzzling\Web\Value\FirstTryNoticeLine;

/**
 * The one place the first-try rules are decided - the add/edit forms, the live check, the handlers and the
 * API all ask here (docs/features/first-try-integrity.md). Costs one query.
 */
readonly final class FirstTryAssessor
{
    public function __construct(
        private GetFirstTryTimes $getFirstTryTimes,
        private ClockInterface $clock,
    ) {
    }

    public function assess(FirstTryEntry $entry): FirstTryAssessment
    {
        $solvedAt = $entry->solvedAt ?? $this->clock->now();
        $members = array_values(array_unique(array_map('strtolower', $entry->memberPlayerIds)));
        $viewer = strtolower($entry->actorPlayerId);

        $times = $this->getFirstTryTimes->ofPlayersOnPuzzle($entry->puzzleId, $members, $entry->editedTimeId);

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
        $someone = false;
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

                if ($person->masked) {
                    $someone = true;

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

        if ($someone) {
            $lines[] = new FirstTryNoticeLine(kind: FirstTryNoticeLine::SOMEONE, date: null);
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
        $someone = false;

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
                } elseif ($person->masked) {
                    $someone = true;
                } else {
                    $lines[] = new FirstTryNoticeLine(kind: FirstTryNoticeLine::TEAMMATE, date: $time->solvedAt, time: $time, person: $person);
                }
            }
        }

        if ($someone) {
            $lines[] = new FirstTryNoticeLine(kind: FirstTryNoticeLine::SOMEONE, date: null);
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
