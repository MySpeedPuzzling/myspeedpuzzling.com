<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DuplicateResults;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Results\FirstTryTime;
use SpeedPuzzling\Web\Value\DuplicateAssessment;
use SpeedPuzzling\Web\Value\DuplicateNoticeLine;
use SpeedPuzzling\Web\Value\FirstTryEntry;

/**
 * The "same time already saved" check of the add/edit forms (docs/features/duplicate-results.md, Layer 2). Pure:
 * it is fed the rows the first-try rules read anyway (FirstTryAssessor), so the forms pay no extra query.
 */
readonly final class DuplicateAssessor
{
    // The zone the site shows dates in (twig.date.timezone) - "today" means the player's today
    private const string TIME_ZONE = 'Europe/Prague';

    /**
     * @param list<FirstTryTime> $times every result of the puzzle a visible person of the new result took part in,
     *                                  oldest first, the edited one left out
     * @param list<string> $members the visible registered people of the new result
     */
    public function assess(
        FirstTryEntry $entry,
        int $secondsToSolve,
        array $times,
        array $members,
        DateTimeImmutable $solvedAt,
        DateTimeImmutable $now,
    ): DuplicateAssessment {
        $viewer = strtolower($entry->actorPlayerId);
        $day = $solvedAt->format('Y-m-d');
        $today = $now->setTimezone(new DateTimeZone(self::TIME_ZONE))->format('Y-m-d');
        $sameDay = [];
        $otherDay = [];

        foreach ($times as $time) {
            if ($time->secondsToSolve !== $secondsToSolve) {
                continue;
            }

            $line = $this->line($time, $members, $viewer, $today);

            if ($line === null) {
                continue;
            }

            if ($time->solvedDay() === $day) {
                $sameDay[] = $line;
            } else {
                $otherDay[] = $line;
            }
        }

        return new DuplicateAssessment(
            secondsToSolve: $secondsToSolve,
            sameDayLines: $sameDay,
            otherDayLines: $otherDay,
            tolerated: $this->isTolerated($entry, $secondsToSolve, $members, $solvedAt),
        );
    }

    /**
     * @param list<string> $members
     */
    private function line(FirstTryTime $time, array $members, string $viewer, string $today): null|DuplicateNoticeLine
    {
        $savedToday = $time->trackedAt->setTimezone(new DateTimeZone(self::TIME_ZONE))->format('Y-m-d') === $today;

        if ($time->isTrackedBy($viewer)) {
            $with = [];

            foreach ($time->peopleExcept($viewer) as $person) {
                $with[] = $person->label() ?? '';
            }

            return new DuplicateNoticeLine(
                kind: DuplicateNoticeLine::OWN,
                time: $time,
                with: $with,
                savedToday: $savedToday,
                viewable: true,
            );
        }

        if ($time->involves($viewer)) {
            return new DuplicateNoticeLine(
                kind: DuplicateNoticeLine::WITH_VIEWER,
                time: $time,
                person: $time->person($time->trackerPlayerId),
                savedToday: $savedToday,
                viewable: true,
            );
        }

        // Somebody else's result: named after the first teammate of the new result in it
        foreach ($time->people as $person) {
            if ($person->playerId === null || in_array(strtolower($person->playerId), $members, true) === false) {
                continue;
            }

            return new DuplicateNoticeLine(
                kind: DuplicateNoticeLine::TEAMMATE,
                time: $time,
                person: $person,
                savedToday: $savedToday,
                viewable: $this->nobodyMasked($time),
            );
        }

        return null;
    }

    private function nobodyMasked(FirstTryTime $time): bool
    {
        foreach ($time->people as $person) {
            if ($person->masked) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $members
     */
    private function isTolerated(FirstTryEntry $entry, int $secondsToSolve, array $members, DateTimeImmutable $solvedAt): bool
    {
        if ($entry->editedTimeId === null || $entry->previousMemberPlayerIds === null || $entry->previousSolvedAt === null) {
            return false;
        }

        if ($entry->previousSecondsToSolve !== $secondsToSolve) {
            return false;
        }

        if ($entry->previousSolvedAt->format('Y-m-d') !== $solvedAt->format('Y-m-d')) {
            return false;
        }

        $before = array_map('strtolower', $entry->previousMemberPlayerIds);

        return array_diff($members, $before) === [];
    }
}
