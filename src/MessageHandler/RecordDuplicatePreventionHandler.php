<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ResultDuplicatePrevention;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\RecordDuplicatePrevention;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\ResultDuplicatePreventionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Dispatched by whoever caught the prevented save, after the refused dispatch: the add handler's own
 * transaction is rolled back when it refuses, so a row written there would be gone with it.
 */
#[AsMessageHandler]
readonly final class RecordDuplicatePreventionHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private ResultDuplicatePreventionRepository $resultDuplicatePreventionRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(RecordDuplicatePrevention $message): void
    {
        $this->resultDuplicatePreventionRepository->save(new ResultDuplicatePrevention(
            id: Uuid::uuid7(),
            player: $this->playerRepository->get($message->playerId),
            kind: $message->kind,
            timeId: Uuid::fromString($message->timeId),
            puzzleId: Uuid::fromString($message->puzzleId),
            createdAt: $this->clock->now(),
            via: $message->via,
        ));
    }
}
