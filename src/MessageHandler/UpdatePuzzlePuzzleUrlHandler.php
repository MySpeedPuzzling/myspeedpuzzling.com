<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\UpdatePuzzlePuzzleUrl;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class UpdatePuzzlePuzzleUrlHandler
{
    public function __construct(
        private PuzzleRepository $puzzleRepository,
    )
    {

    }

    /**
     * @throws PuzzleNotFound
     */
    public function __invoke(UpdatePuzzlePuzzleUrl $message): void
    {
        $puzzle = $this->puzzleRepository->get($message->puzzleId);

        $puzzle->changePuzzlePuzzleUrl($message->puzzleUrl);
    }
}
