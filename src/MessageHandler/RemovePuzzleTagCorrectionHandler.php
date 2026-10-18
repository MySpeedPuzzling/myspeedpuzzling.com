<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\RemovePuzzleTagCorrection;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\PuzzleTagCorrectionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RemovePuzzleTagCorrectionHandler
{
    public function __construct(
        private PuzzleRepository $puzzleRepository,
        private PuzzleTagCorrectionRepository $correctionRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PuzzleNotFound unknown, or secret now
     */
    public function __invoke(RemovePuzzleTagCorrection $message): void
    {
        $puzzle = $this->puzzleRepository->get($message->puzzleId);

        if ($puzzle->isImageHiddenAt($this->clock->now())) {
            throw new PuzzleNotFound();
        }

        $correction = $this->correctionRepository->findOne($puzzle, $message->tagKey);

        if ($correction !== null) {
            $this->correctionRepository->delete($correction);
        }
    }
}
