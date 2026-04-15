<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\DeleteCompetition;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DeleteCompetitionHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private Connection $database,
    ) {
    }

    public function __invoke(DeleteCompetition $message): void
    {
        $competitionId = $message->competitionId;
        $params = ['id' => $competitionId];

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
    }
}
