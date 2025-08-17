<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantAlreadyConnectedToDifferentPlayer;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\ConnectCompetitionParticipant;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ConnectCompetitionParticipantHandler
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
        private PlayerRepository $playerRepository,
        private LoggerInterface $logger,
        private ClockInterface $clock,
        private GetCompetitionParticipants $getCompetitionParticipants,
    ) {
    }

    /**
     * @throws CompetitionParticipantNotFound
     * @throws PlayerNotFound
     * @throws CompetitionParticipantAlreadyConnectedToDifferentPlayer
     */
    public function __invoke(ConnectCompetitionParticipant $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        // 1. Disconnect existing connection(s)
        $connections = $this->getCompetitionParticipants->getPlayerConnections(
            $message->competitionId,
            $player->id->toString(),
        );

        foreach ($connections as $participantId) {
            $connectedParticipant = $this->participantRepository->get($participantId);
            $connectedParticipant->disconnect();
        }

        if ($message->participantId === null) {
            return;
        }

        // 2. Make new connection
        $participant = $this->participantRepository->get($message->participantId);

        if ($participant->player !== null && $participant->player->id->equals($player->id) === false) {
            $this->logger->warning('Competition participant connection to multiple players', [
                'participant_id' => $participant->id->toString(),
                'existing_connected_player_id' => $participant->player->id->toString(),
                'new_player_id' => $player->id->toString(),
            ]);

            throw new CompetitionParticipantAlreadyConnectedToDifferentPlayer();
        }

        $participant->connect(
            $player,
            $this->clock->now(),
        );
    }
}
