<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\DeleteCompetitionRound;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DeleteCompetitionRoundHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private Connection $database,
    ) {
    }

    public function __invoke(DeleteCompetitionRound $message): void
    {
        $params = ['id' => $message->roundId];

        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_round_id = NULL WHERE competition_round_id = :id',
            $params,
        );
        $this->database->executeStatement(
            'DELETE FROM competition_participant_round WHERE round_id = :id',
            $params,
        );
        $this->database->executeStatement(
            'DELETE FROM competition_team WHERE round_id = :id',
            $params,
        );
        $this->database->executeStatement(
            'DELETE FROM competition_round_puzzle WHERE round_id = :id',
            $params,
        );

        $round = $this->competitionRoundRepository->get($message->roundId);
        $this->competitionRoundRepository->delete($round);
    }
}
