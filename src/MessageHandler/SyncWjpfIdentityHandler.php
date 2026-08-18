<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\WjpfRequestFailed;
use SpeedPuzzling\Web\Message\SyncWjpfIdentity;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Wjpf\WjpfClient;
use SpeedPuzzling\Web\Services\Wjpf\WjpfIdentityRecorder;
use SpeedPuzzling\Web\Value\WjpfPairingStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class SyncWjpfIdentityHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private WjpfClient $wjpfClient,
        private WjpfIdentityRecorder $wjpfIdentityRecorder,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return null|WjpfPairingStatus Null when the player could not be checked at all.
     *
     * @throws PlayerNotFound
     * @throws WjpfRequestFailed
     */
    public function __invoke(SyncWjpfIdentity $message): null|WjpfPairingStatus
    {
        if ($this->wjpfClient->isEnabled() === false) {
            $this->logger->notice('Skipping WJPF sync - integration is not configured', [
                'player_id' => $message->playerId,
            ]);

            return null;
        }

        $player = $this->playerRepository->get($message->playerId);
        $email = $player->email === null ? '' : trim($player->email);

        if ($email === '') {
            return null;
        }

        $playerId = $player->id->toString();
        $wjpfUser = $this->wjpfClient->findUserByEmail(
            $email,
            $message->claim ? $playerId : null,
        );

        if ($wjpfUser === null) {
            $this->wjpfIdentityRecorder->recordNotFound($player, $email, []);

            return WjpfPairingStatus::NotFound;
        }

        // Their column was empty *before* this call, so a claim sent with it was stored.
        // Without --claim we sent nothing, so nothing landed.
        $claimLanded = $message->claim && $wjpfUser->isUnclaimed();

        $conflictingId = null;

        if ($wjpfUser->isUnclaimed() === false && $wjpfUser->isClaimedBy($playerId) === false) {
            $conflictingId = $wjpfUser->mySpeedPuzzlingId;
        }

        $identity = $this->wjpfIdentityRecorder->recordPairing(
            player: $player,
            email: $email,
            wjpfId: $wjpfUser->idJugador,
            wjpfNameUrl: $wjpfUser->nombreUrl,
            conflictingMySpeedPuzzlingId: $conflictingId,
            claimLanded: $claimLanded,
            response: $wjpfUser->raw,
        );

        return $identity->status;
    }
}
