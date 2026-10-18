<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\BoxTextMismatchDecision;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\DismissBoxTextMismatch;
use SpeedPuzzling\Web\Repository\BoxTextMismatchDecisionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Value\DetectedBoxTextMismatch;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DismissBoxTextMismatchHandler
{
    public function __construct(
        private PuzzleRepository $puzzleRepository,
        private PlayerRepository $playerRepository,
        private BoxTextMismatchDecisionRepository $decisionRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PuzzleNotFound unknown, or hidden now for somebody who may not see hidden puzzles
     */
    public function __invoke(DismissBoxTextMismatch $message): void
    {
        $now = $this->clock->now();
        $puzzle = $this->puzzleRepository->get($message->puzzleId);

        if ($message->mayDecideHiddenPuzzles === false && $puzzle->isImageHiddenAt($now)) {
            throw new PuzzleNotFound();
        }

        $valueKey = mb_substr($message->valueKey, 0, DetectedBoxTextMismatch::MAX_VALUE_KEY_LENGTH);

        if ($this->decisionRepository->findOne($puzzle, $message->field, $valueKey) !== null) {
            return;
        }

        $this->decisionRepository->save(new BoxTextMismatchDecision(
            id: Uuid::uuid7(),
            puzzle: $puzzle,
            field: $message->field,
            valueKey: $valueKey,
            decidedBy: $this->playerRepository->get($message->decidedByPlayerId),
            decidedAt: $now,
        ));
    }
}
