<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundHasResults;
use SpeedPuzzling\Web\Message\DeleteCompetitionRound;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Services\SecretPuzzleHides;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DeleteCompetitionRoundHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private Connection $database,
        private SecretPuzzleHides $secretPuzzleHides,
    ) {
    }

    /**
     * @throws CompetitionRoundHasResults
     */
    public function __invoke(DeleteCompetitionRound $message): void
    {
        $params = ['id' => $message->roundId];

        if ($message->refuseWhenItHasResults) {
            $resultsCount = $this->database
                ->executeQuery('SELECT COUNT(*) FROM puzzle_solving_time WHERE competition_round_id = :id', $params)
                ->fetchOne();

            if (is_numeric($resultsCount) && (int) $resultsCount > 0) {
                throw new CompetitionRoundHasResults((int) $resultsCount);
            }
        }

        // Secret puzzles of the rounds going away - re-synced afterwards from the rounds left, never revealed by accident
        /** @var array<string> $roundIds */
        $roundIds = [$message->roundId];
        $secretPuzzleIds = $this->secretPuzzleHides->puzzleIdsOfRounds($roundIds);

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

        $this->secretPuzzleHides->resyncByIds($secretPuzzleIds);
    }
}
