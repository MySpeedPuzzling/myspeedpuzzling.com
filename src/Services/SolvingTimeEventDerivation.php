<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Services\RoundResults\SolvingTimeRoundResolver;
use SpeedPuzzling\Web\Services\SeriesEditions\SeriesEditionResolver;

/**
 * What follows from a time's event link once it is set (an edit, "Link my results" of the creation wizard's backfill
 * path): a series pick's edition (SeriesEditionResolver - an edit is always a fresh evaluation), then the round
 * (SolvingTimeRoundResolver - it depends on the competition and on solo/duo/team, both final only now). The one place
 * both callers take it from (EditPuzzleSolvingTimeHandler, LinkSolvingTimesToEventHandler).
 */
readonly final class SolvingTimeEventDerivation
{
    public function __construct(
        private SeriesEditionResolver $seriesEditionResolver,
        private SolvingTimeRoundResolver $roundResolver,
        private CompetitionRepository $competitionRepository,
    ) {
    }

    public function derive(PuzzleSolvingTime $solvingTime): void
    {
        if ($solvingTime->competitionSeries !== null) {
            $resolution = $this->seriesEditionResolver->resolve($solvingTime);
            $solvingTime->seriesEditionResolved(
                $resolution->competitionId !== null ? $this->competitionRepository->get($resolution->competitionId) : null,
                $resolution->kind,
            );
        }

        $solvingTime->changeCompetitionRound($this->roundResolver->resolve($solvingTime));
    }
}
