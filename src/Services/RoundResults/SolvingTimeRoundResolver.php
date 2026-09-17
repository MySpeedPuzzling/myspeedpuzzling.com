<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\RoundResults;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;

/**
 * The round a solving time belongs to: the round of its competition that has its puzzle and whose category
 * (solo / duo / team) matches how it was solved. A puzzle is in at most one round per category per
 * competition, so there is never a choice to make - and deliberately no date condition, results are
 * added from home and backfilled days later.
 *
 * Used by the time's own add/edit handlers, before the entity is flushed. Changes on the round side are
 * reconciled in bulk by RoundResultsReconciler instead.
 */
readonly final class SolvingTimeRoundResolver
{
    public function __construct(
        private Connection $database,
        private CompetitionRoundRepository $competitionRoundRepository,
    ) {
    }

    public function resolve(PuzzleSolvingTime $solvingTime): null|CompetitionRound
    {
        if ($solvingTime->competition === null) {
            return null;
        }

        $roundId = $this->database->executeQuery(
            <<<SQL
SELECT cr.id
FROM competition_round cr
INNER JOIN competition_round_puzzle crp ON crp.round_id = cr.id
WHERE cr.competition_id = :competitionId
    AND crp.puzzle_id = :puzzleId
    AND cr.category = :category
ORDER BY cr.starts_at
LIMIT 1
SQL,
            [
                'competitionId' => $solvingTime->competition->id->toString(),
                'puzzleId' => $solvingTime->puzzle->id->toString(),
                'category' => $solvingTime->puzzlingType->value,
            ],
        )->fetchOne();

        if (!is_string($roundId)) {
            return null;
        }

        return $this->competitionRoundRepository->get($roundId);
    }
}
