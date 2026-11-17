<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionStanding;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionStanding;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * The write-side check of a standing's rounds (competition toolkit A6): at least one, each a round of the standing's own
 * event and category, none twice (a repeated id is kept once, in its first place).
 */
readonly final class CompetitionStandingRounds
{
    public function __construct(
        private CompetitionRoundRepository $roundRepository,
    ) {
    }

    /**
     * @param list<string> $roundIds
     * @return list<string> lower-cased, in the given order
     * @throws InvalidCompetitionStanding
     */
    public function validated(Competition $competition, RoundCategory $category, array $roundIds): array
    {
        $roundIds = array_values(array_unique(array_map(strtolower(...), $roundIds)));

        if ($roundIds === []) {
            throw new InvalidCompetitionStanding('no_rounds');
        }

        if (count($roundIds) > CompetitionStanding::MAX_ROUNDS) {
            throw new InvalidCompetitionStanding('too_many_rounds');
        }

        $categories = [];
        foreach ($this->roundRepository->ofCompetition($competition) as $round) {
            $categories[$round->id->toString()] = $round->category;
        }

        foreach ($roundIds as $roundId) {
            if (!isset($categories[$roundId])) {
                throw new InvalidCompetitionStanding('unknown_round');
            }

            if ($categories[$roundId] !== $category) {
                throw new InvalidCompetitionStanding('wrong_category');
            }
        }

        return $roundIds;
    }
}
