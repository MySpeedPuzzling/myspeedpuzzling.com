<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\UnlistedPuzzleNotFound;
use SpeedPuzzling\Web\Message\ProcessUnlistedPairing;
use SpeedPuzzling\Web\Message\UndoUnlistedPairing;
use SpeedPuzzling\Web\Repository\UnlistedPuzzleRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportWriteLock;
use SpeedPuzzling\Web\Value\Unlisted\UnlistedPairingOutcome;
use SpeedPuzzling\Web\Value\Unlisted\UnlistedPairingState;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Undo of a pairing from the request (docs/features/data-import/unlisted-puzzles.md "Undo of a pairing"): the
 * player's own, finished, not older than UnlistedPairing::UNDO_DAYS, and no other pairing of the puzzle at work.
 * The taking back itself is the `imports` worker's. **Never held by the safety switch** - like the import's Undo.
 * Asked again while it runs or after it is done, it answers the same.
 */
#[AsMessageHandler]
readonly final class UndoUnlistedPairingHandler
{
    public function __construct(
        private UnlistedPuzzleRepository $unlistedRepository,
        private ImportWriteLock $lock,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws UnlistedPuzzleNotFound
     */
    public function __invoke(UndoUnlistedPairing $message): UnlistedPairingOutcome
    {
        // The player's import lock, first - as "It is this catalogue puzzle", its steps and "Remove" take it
        $this->lock->lockUntilCommit($message->playerId);
        $pairing = $this->unlistedRepository->findPairing($message->pairingId);

        // A pairing whose unlisted puzzle was removed is a record only: nothing of it can be taken back
        if ($pairing === null || $pairing->unlistedPuzzle === null || Uuid::isValid($message->playerId) === false || $pairing->unlistedPuzzle->isOwnedBy($message->playerId) === false) {
            throw new UnlistedPuzzleNotFound();
        }

        $pairingId = $pairing->id->toString();

        if ($pairing->state === UnlistedPairingState::Undoing || $pairing->state === UnlistedPairingState::Undone) {
            return new UnlistedPairingOutcome(UnlistedPairingOutcome::SAME_REQUEST, $pairingId);
        }

        if ($pairing->state === UnlistedPairingState::Running) {
            return new UnlistedPairingOutcome(UnlistedPairingOutcome::ALREADY_RUNNING, $pairingId);
        }

        $now = $this->clock->now();

        if ($pairing->canBeUndone($now) === false) {
            return new UnlistedPairingOutcome(UnlistedPairingOutcome::NOTHING, $pairingId);
        }

        if ($this->unlistedRepository->workingPairingOf($pairing->unlistedPuzzle) !== null) {
            return new UnlistedPairingOutcome(UnlistedPairingOutcome::ALREADY_RUNNING, $pairingId);
        }

        $pairing->undoRequested($now);
        $this->messageBus->dispatch(new ProcessUnlistedPairing($pairingId));

        return new UnlistedPairingOutcome(UnlistedPairingOutcome::STARTED, $pairingId);
    }
}
