<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\WjpcParticipantNotFound;
use SpeedPuzzling\Web\Message\UpdateWjpcPlayerId;
use SpeedPuzzling\Web\Repository\WjpcParticipantRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
readonly final class UpdateWjpcPlayerIdHandler
{
    public function __construct(
        private WjpcParticipantRepository $wjpcParticipantRepository,
        private HttpClientInterface $client,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws WjpcParticipantNotFound
     */
    public function __invoke(UpdateWjpcPlayerId $message): void
    {
        $participant = $this->wjpcParticipantRepository->get($message->participantId);

        if ($participant->player === null) {
            $this->logger->notice('Skipping WJPC player update - not connected', [
                'participant_id' => $message->participantId,
            ]);

            return;
        }

        $url = sprintf('https://www.worldjigsawpuzzle.org/users/form_pr.php?accion=update_player_id&name=%s&player_id=%s',
            $participant->name,
            $participant->player->id,
        );

        $response = $this->client->request('GET', $url);

        if ($response->getStatusCode() === 200) {
            /** @var array{idjugador: int} $data */
            $data = $response->toArray();

            $participant->updateRemoteId((string) $data['idjugador']);
        }
    }
}
