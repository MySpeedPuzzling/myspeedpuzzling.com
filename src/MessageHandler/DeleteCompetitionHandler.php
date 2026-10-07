<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\CompetitionHasResults;
use SpeedPuzzling\Web\Message\DeleteCompetition;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Services\OfficialResultsGuard;
use SpeedPuzzling\Web\Services\SecretPuzzleHides;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DeleteCompetitionHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private Connection $database,
        private SecretPuzzleHides $secretPuzzleHides,
        private OfficialResultsGuard $officialResultsGuard,
    ) {
    }

    public function __invoke(DeleteCompetition $message): void
    {
        $competitionId = $message->competitionId;
        $params = ['id' => $competitionId];

        if ($message->refuseWhenItHasResults) {
            $resultsCount = $this->database->fetchOne(
                'SELECT COUNT(*) FROM puzzle_solving_time
                 WHERE competition_id = :id
                    OR competition_round_id IN (SELECT id FROM competition_round WHERE competition_id = :id)',
                $params,
            );

            // Official results recorded by the organiser count as results too - a qualified mark as well
            $resultsCount = (is_numeric($resultsCount) ? (int) $resultsCount : 0)
                + $this->officialResultsGuard->countEntriesWithOfficialDataInCompetition($competitionId);

            if ($resultsCount > 0) {
                throw new CompetitionHasResults($resultsCount);
            }
        }

        // Secret puzzles of the rounds going away - re-synced afterwards from the rounds left, never revealed by accident
        /** @var array<string> $roundIds */
        $roundIds = $this->database->fetchFirstColumn('SELECT id FROM competition_round WHERE competition_id = :id', $params);
        // Locks the rounds, then their secret puzzles - waits for every other change of them (SecretPuzzleHides)
        $secretPuzzleIds = $this->secretPuzzleHides->lockRoundsForChange($roundIds);

        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_round_id = NULL
             WHERE competition_round_id IN (SELECT id FROM competition_round WHERE competition_id = :id)',
            $params,
        );
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_id = NULL WHERE competition_id = :id',
            $params,
        );

        $this->database->executeStatement(
            'DELETE FROM competition_participant_round
             WHERE participant_id IN (SELECT id FROM competition_participant WHERE competition_id = :id)
                OR round_id IN (SELECT id FROM competition_round WHERE competition_id = :id)',
            $params,
        );
        $this->database->executeStatement(
            'DELETE FROM competition_team
             WHERE round_id IN (SELECT id FROM competition_round WHERE competition_id = :id)',
            $params,
        );
        $this->database->executeStatement(
            'DELETE FROM competition_participant WHERE competition_id = :id',
            $params,
        );
        $this->database->executeStatement(
            'DELETE FROM competition_round_puzzle
             WHERE round_id IN (SELECT id FROM competition_round WHERE competition_id = :id)',
            $params,
        );
        $this->database->executeStatement(
            'DELETE FROM competition_round WHERE competition_id = :id',
            $params,
        );

        $competition = $this->competitionRepository->get($competitionId);
        $this->competitionRepository->delete($competition);

        $this->secretPuzzleHides->resyncByIds($secretPuzzleIds);
    }
}
