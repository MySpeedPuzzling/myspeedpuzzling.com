<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionDivision;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionStanding;
use SpeedPuzzling\Web\Repository\CompetitionDivisionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundDivisionRepository;
use SpeedPuzzling\Web\Value\StandingMode;

/**
 * The write-side check of a standing's division (real events L2a, shared-puzzles-and-divisions.md D7): a division of the
 * standing's own event (`unknown_division`) that every round of the standing uses (`division_not_in_round` - a round
 * without it would rank nobody of the division and never count). A `stages` standing has no division (the bracket's
 * places are the overall ones) - whatever the form sent is dropped.
 */
readonly final class CompetitionStandingDivision
{
    public function __construct(
        private CompetitionDivisionRepository $divisionRepository,
        private CompetitionRoundDivisionRepository $roundDivisionRepository,
    ) {
    }

    /**
     * @param list<string> $roundIds the validated rounds (CompetitionStandingRounds), lower case
     * @throws InvalidCompetitionStanding
     */
    public function validated(Competition $competition, null|string $divisionId, array $roundIds, StandingMode $mode): null|CompetitionDivision
    {
        if ($divisionId === null || $divisionId === '' || $mode === StandingMode::Stages) {
            return null;
        }

        $divisionId = strtolower($divisionId);
        $division = array_find(
            $this->divisionRepository->ofCompetition($competition),
            static fn (CompetitionDivision $division): bool => strtolower($division->id->toString()) === $divisionId,
        );

        if ($division === null) {
            throw new InvalidCompetitionStanding('unknown_division');
        }

        $using = [];
        foreach ($this->roundDivisionRepository->ofCompetition($competition) as $roundDivision) {
            if (strtolower($roundDivision->division->id->toString()) === $divisionId) {
                $using[strtolower($roundDivision->round->id->toString())] = true;
            }
        }

        foreach ($roundIds as $roundId) {
            if (!isset($using[strtolower($roundId)])) {
                throw new InvalidCompetitionStanding('division_not_in_round');
            }
        }

        return $division;
    }
}
