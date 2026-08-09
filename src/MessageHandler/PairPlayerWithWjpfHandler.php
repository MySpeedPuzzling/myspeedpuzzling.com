<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\PairPlayerWithWjpf;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Wjpf\WjpfIdentityRecorder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class PairPlayerWithWjpfHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private WjpfIdentityRecorder $wjpfIdentityRecorder,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(PairPlayerWithWjpf $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        $this->wjpfIdentityRecorder->recordPairing(
            player: $player,
            email: $message->email,
            wjpfId: $message->wjpfId,
            wjpfNameUrl: $message->wjpfNameUrl,
            // They asked us who owns this address, so by definition their record is about to
            // point at the player we answered with - there is nothing to conflict with.
            conflictingMySpeedPuzzlingId: null,
            // Their side performs the write off the back of our response; we have not
            // observed it, so this is not evidence that it landed.
            claimLanded: false,
            response: [
                'source' => 'wjpf_inbound',
                'idjugador' => $message->wjpfId,
                'nombreurl' => $message->wjpfNameUrl,
                'email' => $message->email,
            ],
        );
    }
}
