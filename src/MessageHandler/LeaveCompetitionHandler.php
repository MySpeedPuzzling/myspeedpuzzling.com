<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\LeaveCompetition;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Value\ParticipantSource;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class LeaveCompetitionHandler
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(LeaveCompetition $message): void
    {
        $participantId = $this->findPlayerParticipant($message->competitionId, $message->playerId);

        if ($participantId === null) {
            return;
        }

        $participant = $this->participantRepository->get($participantId);

        if ($participant->source === ParticipantSource::SelfJoined) {
            $participant->softDelete($this->clock->now());
        } else {
            $participant->disconnect();
        }
    }

    private function findPlayerParticipant(string $competitionId, string $playerId): null|string
    {
        $query = <<<SQL
SELECT id FROM competition_participant
WHERE competition_id = :competitionId
AND player_id = :playerId
AND deleted_at IS NULL
LIMIT 1
SQL;

        /** @var false|string $result */
        $result = $this->database->executeQuery($query, [
            'competitionId' => $competitionId,
            'playerId' => $playerId,
        ])->fetchOne();

        return $result !== false ? $result : null;
    }
}
