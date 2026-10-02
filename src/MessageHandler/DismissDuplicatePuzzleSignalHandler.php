<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\DuplicatePuzzleSignalAlreadyResolved;
use SpeedPuzzling\Web\Exceptions\DuplicatePuzzleSignalNotFound;
use SpeedPuzzling\Web\Message\DismissDuplicatePuzzleSignal;
use SpeedPuzzling\Web\Repository\DuplicatePuzzleSignalRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Two different puzzles after all - the signal stays, so the detection never raises the pair again.
 */
#[AsMessageHandler]
readonly final class DismissDuplicatePuzzleSignalHandler
{
    public function __construct(
        private DuplicatePuzzleSignalRepository $signalRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DuplicatePuzzleSignalNotFound
     * @throws DuplicatePuzzleSignalAlreadyResolved
     */
    public function __invoke(DismissDuplicatePuzzleSignal $message): void
    {
        $this->signalRepository->get($message->signalId)->dismiss($this->clock->now());
    }
}
