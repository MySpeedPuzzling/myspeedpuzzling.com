<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleTagCorrection;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleTagKey;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\CorrectPuzzleTag;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\PuzzleTagCorrectionRepository;
use SpeedPuzzling\Web\Value\PuzzleTaxonomy;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class CorrectPuzzleTagHandler
{
    public function __construct(
        private PuzzleRepository $puzzleRepository,
        private PlayerRepository $playerRepository,
        private PuzzleTagCorrectionRepository $correctionRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PuzzleNotFound unknown, or secret now (a secret puzzle has no tags to correct)
     * @throws InvalidPuzzleTagKey
     */
    public function __invoke(CorrectPuzzleTag $message): void
    {
        if (PuzzleTaxonomy::isWellFormedKey($message->tagKey) === false) {
            throw new InvalidPuzzleTagKey($message->tagKey);
        }

        $now = $this->clock->now();
        $puzzle = $this->puzzleRepository->get($message->puzzleId);

        if ($puzzle->isImageHiddenAt($now)) {
            throw new PuzzleNotFound();
        }

        $decidedBy = $this->playerRepository->get($message->decidedByPlayerId);
        $note = $message->note !== null && trim($message->note) !== '' ? trim($message->note) : null;
        $correction = $this->correctionRepository->findOne($puzzle, $message->tagKey);

        if ($correction !== null) {
            $correction->change($message->verdict, $note, $decidedBy, $now, PuzzleTaxonomy::VERSION);

            return;
        }

        $this->correctionRepository->save(new PuzzleTagCorrection(
            id: Uuid::uuid7(),
            puzzle: $puzzle,
            tagKey: $message->tagKey,
            verdict: $message->verdict,
            note: $note,
            decidedBy: $decidedBy,
            decidedAt: $now,
            taxonomyVersion: PuzzleTaxonomy::VERSION,
        ));
    }
}
