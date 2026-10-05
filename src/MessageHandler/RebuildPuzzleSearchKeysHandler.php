<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\RebuildPuzzleSearchKeys;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Returns the ids of the puzzles that got a different key - an unchanged key is no write, so running it again is cheap.
 *
 * The batch is locked for update: a moderator's edit or a merge committing between loading the batch and its flush
 * would otherwise get its fresh key overwritten by one built from the names as they were loaded. Now it waits for the
 * batch (one batch of 500 takes ~0.15 s on a copy of production), or the batch reads its result.
 */
#[AsMessageHandler]
readonly final class RebuildPuzzleSearchKeysHandler
{
    public function __construct(
        private PuzzleRepository $puzzleRepository,
    ) {
    }

    /**
     * @return list<string>
     */
    public function __invoke(RebuildPuzzleSearchKeys $message): array
    {
        $changed = [];

        foreach ($this->puzzleRepository->findByIdsForUpdate($message->puzzleIds) as $puzzle) {
            $keys = [$puzzle->searchNames, $puzzle->searchCodes];

            $puzzle->refreshSearchKeys();

            if ($keys !== [$puzzle->searchNames, $puzzle->searchCodes]) {
                $changed[] = $puzzle->id->toString();
            }
        }

        return $changed;
    }
}
