<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\WjpcParticipantAlreadyConnectedToDifferentPlayer;
use SpeedPuzzling\Web\Exceptions\WjpcParticipantNotFound;
use SpeedPuzzling\Web\Message\ConnectWjpcParticipant;
use SpeedPuzzling\Web\Query\GetWjpcParticipants;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\WjpcParticipantRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ConnectWjpcParticipantHandler
{
    public function __construct(
        private WjpcParticipantRepository $participantRepository,
        private PlayerRepository $playerRepository,
        private LoggerInterface $logger,
        private ClockInterface $clock,
        private GetWjpcParticipants $getWjpcParticipants,
    ) {
    }

    /**
     * @throws WjpcParticipantNotFound
     * @throws PlayerNotFound
     * @throws WjpcParticipantAlreadyConnectedToDifferentPlayer
     */
    public function __invoke(ConnectWjpcParticipant $message): void
    {
        if ($message->participantName === null) {
            throw new WjpcParticipantNotFound();
        }

        $player = $this->playerRepository->get($message->playerId);
        $participant = $this->participantRepository->get($message->participantName);

        if ($participant->player !== null && $participant->player->id->equals($player->id) === false) {
            $this->logger->warning('WJPC participant connection to multiple players', [
                'existing_connected_player_id' => $participant->player->id->toString(),
                'new_player_id' => $player->id->toString(),
            ]);

            throw new WjpcParticipantAlreadyConnectedToDifferentPlayer();
        }

        $connections = $this->getWjpcParticipants->getPlayerConnections($player->id->toString());
        foreach ($connections as $participantId) {
            $connectedParticipant = $this->participantRepository->get($participantId);
            $connectedParticipant->disconnect();
        }

        $participant->connect(
            $player,
            $this->clock->now(),
        );
    }
}
