<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\UnlistedPuzzleNotFound;
use SpeedPuzzling\Web\Message\RemoveUnlistedPuzzle;
use SpeedPuzzling\Web\Repository\UnlistedPuzzleRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportWriteLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Remove" (docs/features/data-import/unlisted-puzzles.md): the player's own unlisted puzzle goes with its unlisted
 * results and marks - under the player's import lock, so no row of an "Add" and no step of a pairing writes to it
 * meanwhile. What became a real result or item of a paired puzzle stays where it is: then the unlisted puzzle stays
 * too, as the record of the pairing, with nothing unlisted left on it. Not while a pairing of it is at work.
 *
 * The rows of the import that made them keep saying "added": reading the same file again does not bring them back.
 */
#[AsMessageHandler]
readonly final class RemoveUnlistedPuzzleHandler
{
    public function __construct(
        private UnlistedPuzzleRepository $unlistedRepository,
        private ImportWriteLock $lock,
    ) {
    }

    /**
     * @return bool false: a pairing of it is at work - nothing was removed
     *
     * @throws UnlistedPuzzleNotFound
     */
    public function __invoke(RemoveUnlistedPuzzle $message): bool
    {
        $unlistedPuzzle = $this->unlistedRepository->getOwnedBy($message->unlistedPuzzleId, $message->playerId);
        $this->lock->lockUntilCommit($unlistedPuzzle->player->id->toString());

        if ($this->unlistedRepository->workingPairingOf($unlistedPuzzle) !== null) {
            return false;
        }

        $somethingIsReal = false;

        foreach ($this->unlistedRepository->resultsOf($unlistedPuzzle) as $result) {
            if ($result->isUnlisted()) {
                $this->unlistedRepository->deleteResult($result);
            } else {
                $somethingIsReal = true;
            }
        }

        foreach ($this->unlistedRepository->ownershipsOf($unlistedPuzzle) as $ownership) {
            if ($ownership->isUnlisted()) {
                $this->unlistedRepository->deleteOwnership($ownership);
            } else {
                $somethingIsReal = true;
            }
        }

        if ($somethingIsReal === false) {
            $this->unlistedRepository->delete($unlistedPuzzle);
        }

        return true;
    }
}
