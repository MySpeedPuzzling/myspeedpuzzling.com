<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\RebuildPuzzleSearchKeys;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Returns how many of the puzzles got a different key - an unchanged key is no write, so running it again is cheap.
 */
#[AsMessageHandler]
readonly final class RebuildPuzzleSearchKeysHandler
{
    public function __construct(
        private PuzzleRepository $puzzleRepository,
    ) {
    }

    public function __invoke(RebuildPuzzleSearchKeys $message): int
    {
        $changed = 0;

        foreach ($this->puzzleRepository->findByIds($message->puzzleIds) as $puzzle) {
            $keys = [$puzzle->searchNames, $puzzle->searchCodes];

            $puzzle->refreshSearchKeys();

            if ($keys !== [$puzzle->searchNames, $puzzle->searchCodes]) {
                $changed++;
            }
        }

        return $changed;
    }
}
