<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\RoundResults;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * The round a solving time belongs to: the round of its competition that has its puzzle and whose category
 * (solo / duo / team) matches how it was solved. A puzzle is in at most one round per category per
 * competition, so there is never a choice to make - and deliberately no date condition, results are
 * added from home and backfilled days later.
 *
 * Used by the time's own add/edit handlers, before the entity is flushed - and, through resolveFor(), by the unfinished
 * result's handlers before the entity even exists (an unfinished result needs its round's limit first). One statement
 * for both, so they can never disagree. Changes on the round side are reconciled in bulk by RoundResultsReconciler instead.
 */
readonly final class SolvingTimeRoundResolver
{
    /**
     * SQL twin of PuzzleSolvingTime::keepsItsRound(), negated, for a `pst` alias of puzzle_solving_time: whether the
     * time's round follows the rule above. An imported event result keeps the round its import gave it (a pair at a
     * teams event is ranked in the teams round - docs/features/event-results-import/README.md D10). IS DISTINCT FROM:
     * results saved before created_via existed have none and follow the rule.
     */
    public const string SQL_ROUND_IS_DERIVED = "(pst.created_via IS DISTINCT FROM 'event_import' OR pst.competition_round_id IS NULL)";

    public function __construct(
        private Connection $database,
        private CompetitionRoundRepository $competitionRoundRepository,
    ) {
    }

    public function resolve(PuzzleSolvingTime $solvingTime): null|CompetitionRound
    {
        if ($solvingTime->keepsItsRound()) {
            return $solvingTime->competitionRound;
        }

        if ($solvingTime->competition === null) {
            return null;
        }

        return $this->resolveFor(
            $solvingTime->competition->id->toString(),
            $solvingTime->puzzle->id->toString(),
            $solvingTime->puzzlingType,
        );
    }

    public function resolveFor(string $competitionId, string $puzzleId, PuzzlingType $puzzlingType): null|CompetitionRound
    {
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
                'competitionId' => $competitionId,
                'puzzleId' => $puzzleId,
                // RoundCategory and PuzzlingType share their values (solo / duo / team)
                'category' => $puzzlingType->value,
            ],
        )->fetchOne();

        if (!is_string($roundId)) {
            return null;
        }

        return $this->competitionRoundRepository->get($roundId);
    }
}
