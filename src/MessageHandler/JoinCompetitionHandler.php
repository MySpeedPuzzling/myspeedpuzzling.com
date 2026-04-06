<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantAlreadyConnectedToDifferentPlayer;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\ParticipantSource;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class JoinCompetitionHandler
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
        private CompetitionRepository $competitionRepository,
        private PlayerRepository $playerRepository,
        private GetCompetitionParticipants $getCompetitionParticipants,
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CompetitionParticipantAlreadyConnectedToDifferentPlayer
     */
    public function __invoke(JoinCompetition $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        if ($message->participantId !== null) {
            // Picking from organizer's list — disconnect existing, connect to selected
            $this->disconnectExisting($message->competitionId, $message->playerId);

            $participant = $this->participantRepository->get($message->participantId);

            if ($participant->player !== null && $participant->player->id->equals($player->id) === false) {
                throw new CompetitionParticipantAlreadyConnectedToDifferentPlayer();
            }

            $participant->connect($player, $this->clock->now());

            return;
        }

        // Self-join — check for soft-deleted record to restore
        $existingId = $this->findSoftDeletedSelfJoin($message->competitionId, $message->playerId);

        if ($existingId !== null) {
            $existing = $this->participantRepository->get($existingId);
            $existing->restore();
            $existing->connect($player, $this->clock->now());

            return;
        }

        // Create new self-join participant
        $competition = $this->competitionRepository->get($message->competitionId);

        $participant = new CompetitionParticipant(
            id: Uuid::uuid7(),
            name: $player->name ?? $player->code,
            country: $player->country,
            competition: $competition,
            source: ParticipantSource::SelfJoined,
        );

        $participant->connect($player, $this->clock->now());

        $this->participantRepository->save($participant);
    }

    private function disconnectExisting(string $competitionId, string $playerId): void
    {
        $connections = $this->getCompetitionParticipants->getPlayerConnections($competitionId, $playerId);

        foreach ($connections as $participantId) {
            $participant = $this->participantRepository->get($participantId);
            $participant->disconnect();
        }
    }

    private function findSoftDeletedSelfJoin(string $competitionId, string $playerId): null|string
    {
        $query = <<<SQL
SELECT id FROM competition_participant
WHERE competition_id = :competitionId
AND player_id = :playerId
AND deleted_at IS NOT NULL
AND source = :source
LIMIT 1
SQL;

        /** @var false|string $result */
        $result = $this->database->executeQuery($query, [
            'competitionId' => $competitionId,
            'playerId' => $playerId,
            'source' => ParticipantSource::SelfJoined->value,
        ])->fetchOne();

        return $result !== false ? $result : null;
    }
}
