<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\NotAMemberOfPuzzlingTeam;
use SpeedPuzzling\Web\Exceptions\PuzzlingTeamNotFound;
use SpeedPuzzling\Web\Message\ArchivePuzzlingTeam;
use SpeedPuzzling\Web\Repository\PuzzlingTeamRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ArchivePuzzlingTeamHandler
{
    public function __construct(
        private PuzzlingTeamRepository $puzzlingTeamRepository,
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PuzzlingTeamNotFound
     * @throws NotAMemberOfPuzzlingTeam
     */
    public function __invoke(ArchivePuzzlingTeam $message): void
    {
        $team = $this->puzzlingTeamRepository->get($message->teamId);

        if ($this->puzzlingTeamRepository->isMember($team, $message->playerId) === false) {
            throw new NotAMemberOfPuzzlingTeam();
        }

        if ($message->archive === false) {
            $this->connection->executeStatement(
                'DELETE FROM puzzling_team_archive WHERE team_id = :teamId AND player_id = :playerId',
                ['teamId' => $message->teamId, 'playerId' => $message->playerId],
            );

            return;
        }

        // Archiving twice is archiving once
        $this->connection->executeStatement(
            <<<SQL
INSERT INTO puzzling_team_archive (id, team_id, player_id, archived_at)
VALUES (:id, :teamId, :playerId, :archivedAt)
ON CONFLICT (team_id, player_id) DO NOTHING
SQL,
            [
                'id' => Uuid::uuid7()->toString(),
                'teamId' => $message->teamId,
                'playerId' => $message->playerId,
                'archivedAt' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
        );
    }
}
