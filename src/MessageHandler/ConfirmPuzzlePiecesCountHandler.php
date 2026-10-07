<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\SuspiciousTimePuzzleConfirmation;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleIsStillSecret;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\SuspiciousTimeCaseChanged;
use SpeedPuzzling\Web\Message\ConfirmPuzzlePiecesCount;
use SpeedPuzzling\Web\Query\IsPuzzleKeptSecret;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimePuzzleConfirmationRepository;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeDecisionRecorder;
use SpeedPuzzling\Web\Value\SuspiciousTimeDecisionKind;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Piece count is right" on a puzzle card (docs/features/suspicious-time-review.md, "The piece count is wrong"): the
 * confirmation is bound to the piece count - a later change of the count lapses it and the card can come back. A puzzle
 * a competition keeps secret has no card (its times stay out of the queue until the reveal) and is refused.
 */
#[AsMessageHandler]
readonly final class ConfirmPuzzlePiecesCountHandler
{
    public function __construct(
        private PuzzleRepository $puzzleRepository,
        private PlayerRepository $playerRepository,
        private SuspiciousTimePuzzleConfirmationRepository $suspiciousTimePuzzleConfirmationRepository,
        private SuspiciousTimeDecisionRecorder $suspiciousTimeDecisionRecorder,
        private IsPuzzleKeptSecret $isPuzzleKeptSecret,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PuzzleNotFound
     * @throws PuzzleIsStillSecret
     * @throws PlayerNotFound
     * @throws SuspiciousTimeCaseChanged
     */
    public function __invoke(ConfirmPuzzlePiecesCount $message): void
    {
        $puzzle = $this->puzzleRepository->get($message->puzzleId);

        if ($this->isPuzzleKeptSecret->byId($puzzle->id->toString())) {
            throw new PuzzleIsStillSecret($puzzle->id->toString());
        }

        $moderator = $this->playerRepository->get($message->decidedById);
        $now = $this->clock->now();

        // The puzzle was fixed meanwhile - the card the moderator saw was about another piece count
        if ($puzzle->piecesCount !== $message->seenPiecesCount) {
            throw new SuspiciousTimeCaseChanged();
        }

        $confirmation = $this->suspiciousTimePuzzleConfirmationRepository->find($puzzle->id->toString());

        if ($confirmation === null) {
            $this->suspiciousTimePuzzleConfirmationRepository->save(new SuspiciousTimePuzzleConfirmation(
                puzzle: $puzzle,
                piecesCount: $puzzle->piecesCount,
                confirmedById: $moderator->id,
                confirmedAt: $now,
            ));
        } else {
            $confirmation->confirmAgain($puzzle->piecesCount, $moderator->id, $now);
        }

        $this->suspiciousTimeDecisionRecorder->recordAboutPuzzle(
            SuspiciousTimeDecisionKind::PiecesConfirmed,
            $puzzle,
            $moderator,
        );
    }
}
