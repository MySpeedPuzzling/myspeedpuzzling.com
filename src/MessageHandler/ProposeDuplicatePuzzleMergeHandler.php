<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\DuplicatePuzzleSignalAlreadyResolved;
use SpeedPuzzling\Web\Exceptions\DuplicatePuzzleSignalNotFound;
use SpeedPuzzling\Web\Message\ProposeDuplicatePuzzleMerge;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Repository\DuplicatePuzzleSignalRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "Propose merge" on a catalogue signal (docs/features/duplicate-results.md, Layer 4): files a regular merge request
 * with the admin as reporter - the merge review settles the merged values and moves every result to the survivor,
 * the detection then finds the twins among them. In one transaction with the signal, so neither happens alone.
 */
#[AsMessageHandler]
readonly final class ProposeDuplicatePuzzleMergeHandler
{
    public function __construct(
        private DuplicatePuzzleSignalRepository $signalRepository,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DuplicatePuzzleSignalNotFound
     * @throws DuplicatePuzzleSignalAlreadyResolved
     */
    public function __invoke(ProposeDuplicatePuzzleMerge $message): void
    {
        $signal = $this->signalRepository->get($message->signalId);

        $signal->proposeMerge(Uuid::fromString($message->mergeRequestId), $this->clock->now());

        $this->messageBus->dispatch(new SubmitPuzzleMergeRequest(
            mergeRequestId: $message->mergeRequestId,
            sourcePuzzleId: $signal->puzzleA->id->toString(),
            reporterId: $message->playerId,
            duplicatePuzzleIds: [$signal->puzzleB->id->toString()],
        ));
    }
}
