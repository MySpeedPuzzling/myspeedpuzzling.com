<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DismissedHint;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\DismissHint;
use SpeedPuzzling\Web\Repository\DismissedHintRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DismissHintHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private DismissedHintRepository $dismissedHintRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(DismissHint $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        $existing = $this->dismissedHintRepository->findByPlayerAndType($player, $message->type);
        if ($existing !== null) {
            return;
        }

        $dismissedHint = new DismissedHint(
            id: Uuid::uuid7(),
            player: $player,
            type: $message->type,
            dismissedAt: new DateTimeImmutable(),
        );

        $this->dismissedHintRepository->save($dismissedHint);
    }
}
