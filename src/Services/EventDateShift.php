<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Value\DuplicateDateShift;

/**
 * An event's dates moved by a new first day (docs/features/competitions-management/README.md "Duplicating an event"
 * and "Moving an event's date"): the one rule of a copy with a new date (DuplicateCompetition) and of an edit that moves
 * the rounds along (EditCompetition `moveRoundsWithDate`). Whole calendar days (DuplicateDateShift), every moment
 * keeping its wall-clock time in its own zone.
 */
readonly final class EventDateShift
{
    public function __construct(
        private CompetitionRoundRepository $roundRepository,
        private OfficialResultsGuard $officialResultsGuard,
    ) {
    }

    /**
     * The day everything dated moves with: the event's first day, else the day of its first round in the round's zone
     * (an online edition is often dated by its rounds only - OccurrenceDates). Null: nothing dated.
     */
    public function firstDayOf(Competition $competition): null|DateTimeImmutable
    {
        if ($competition->dateFrom !== null) {
            return $competition->dateFrom;
        }

        $first = null;

        foreach ($this->roundRepository->ofCompetition($competition) as $round) {
            if ($first === null || $round->startsAt < $first->startsAt) {
                $first = $round;
            }
        }

        if ($first === null) {
            return null;
        }

        $day = $first->startsAt->setTimezone(new DateTimeZone($first->displayTimezone()))->format('Y-m-d');

        return new DateTimeImmutable($day, new DateTimeZone('UTC'));
    }

    /**
     * From the event's first day (firstDayOf()) to the new one - no days when either is missing
     */
    public function shiftTo(Competition $competition, null|DateTimeImmutable $newDateFrom): DuplicateDateShift
    {
        return DuplicateDateShift::between($this->firstDayOf($competition), $newDateFrom);
    }

    /**
     * Whether the event's rounds may move with its date automatically: it has rounds and none of them has begun -
     * no stopwatch state at all (a scheduled start included: its countdown is the organiser's own moment, so the rounds
     * stay and are moved on the Rounds page), no official result, and no secret puzzle (a moved start moves its reveal,
     * which the round form confirms puzzle by puzzle - SecretRevealPreview).
     */
    public function roundsCanMove(Competition $competition): bool
    {
        $rounds = $this->roundRepository->ofCompetition($competition);

        if ($rounds === []) {
            return false;
        }

        foreach ($rounds as $round) {
            if ($this->roundMustStay($round)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Moves every round's start and the registration window by the shift (call it before the event's own fields change:
     * a round without a saved zone is read in the zone of the event's country as it is now). Only when
     * roundsCanMove() said yes.
     *
     * @return list<string> the ids of the rounds moved
     */
    public function moveRoundsAndRegistration(Competition $competition, DuplicateDateShift $shift): array
    {
        if ($shift->days === 0) {
            return [];
        }

        $moved = [];

        foreach ($this->roundRepository->ofCompetition($competition) as $round) {
            $round->moveStartBy($shift);
            $moved[] = $round->id->toString();
        }

        $competition->moveRegistrationWindowBy($shift);

        return $moved;
    }

    private function roundMustStay(CompetitionRound $round): bool
    {
        if ($round->stopwatchStatus !== null) {
            return true;
        }

        foreach ($round->roundPuzzles as $roundPuzzle) {
            if ($roundPuzzle->hideUntilRoundStarts) {
                return true;
            }
        }

        return $this->officialResultsGuard->roundHasResults($round->id->toString());
    }
}
