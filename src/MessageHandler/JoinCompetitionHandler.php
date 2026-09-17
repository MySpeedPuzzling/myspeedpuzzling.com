<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantAlreadyConnectedToDifferentPlayer;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
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
     * @throws CompetitionParticipantNotFound
     * @throws CompetitionParticipantAlreadyConnectedToDifferentPlayer
     */
    public function __invoke(JoinCompetition $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        if ($message->participantId !== null) {
            $participant = $this->participantRepository->get($message->participantId);

            // Validate everything before touching the player's current rows: a rolled-back handler
            // leaves its changed entities in the entity manager, and the next flush of the same
            // request would still write them
            if ($participant->competition->id->toString() !== $message->competitionId || $participant->isDeleted()) {
                throw new CompetitionParticipantNotFound();
            }

            if ($participant->player !== null && $participant->player->id->equals($player->id) === false) {
                throw new CompetitionParticipantAlreadyConnectedToDifferentPlayer();
            }

            $this->releaseOtherParticipants($message->competitionId, $player, keep: $participant);
            $participant->connect($player, $this->clock->now());

            return;
        }

        if ($this->getCompetitionParticipants->isPlayerSelfJoined($message->competitionId, $message->playerId)) {
            return;
        }

        // "Not on the list" — whatever organizer's row the player was connected to is not them
        $this->releaseOtherParticipants($message->competitionId, $player, keep: null);

        $existingId = $this->findSoftDeletedSelfJoin($message->competitionId, $message->playerId);

        if ($existingId !== null) {
            $existing = $this->participantRepository->get($existingId);
            $existing->restore();
            $existing->connect($player, $this->clock->now());

            return;
        }

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

    /**
     * A self-joined row is the player's own and goes away; an organizer's row stays on their list.
     */
    private function releaseOtherParticipants(string $competitionId, Player $player, null|CompetitionParticipant $keep): void
    {
        $connections = $this->getCompetitionParticipants->getPlayerConnections($competitionId, $player->id->toString());

        foreach ($connections as $participantId) {
            if ($keep !== null && $keep->id->toString() === $participantId) {
                continue;
            }

            $participant = $this->participantRepository->get($participantId);

            if ($participant->source === ParticipantSource::SelfJoined) {
                $participant->softDelete($this->clock->now());
            } else {
                $participant->disconnect();
            }
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
