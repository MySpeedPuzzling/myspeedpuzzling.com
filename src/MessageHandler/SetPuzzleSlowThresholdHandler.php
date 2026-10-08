<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\SuspiciousTimePuzzleConfirmation;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleIsStillSecret;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\SuspiciousTimeCaseChanged;
use SpeedPuzzling\Web\Message\SetPuzzleSlowThreshold;
use SpeedPuzzling\Web\Query\IsPuzzleKeptSecret;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimePuzzleConfirmationRepository;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeDecisionRecorder;
use SpeedPuzzling\Web\Value\SuspiciousTimeDecisionKind;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "A hard puzzle" (docs/features/suspicious-time-review.md): the threshold is bound to the puzzle's piece count - a
 * later change of the count lapses it. A puzzle a competition keeps secret has no times in the queue and is refused.
 * Setting it moves confirmedAt, which makes the scan judge every time of the puzzle checked before again; the decision
 * log keeps the threshold and the one before. Nothing changes, nothing is recorded, when the threshold stays the same.
 */
#[AsMessageHandler]
readonly final class SetPuzzleSlowThresholdHandler
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
    public function __invoke(SetPuzzleSlowThreshold $message): void
    {
        if ($message->slowThreshold !== null && SetPuzzleSlowThreshold::isValid($message->slowThreshold) === false) {
            throw new InvalidArgumentException(sprintf('A slow threshold is between %s and %s.', SetPuzzleSlowThreshold::MIN, SetPuzzleSlowThreshold::MAX));
        }

        $puzzle = $this->puzzleRepository->get($message->puzzleId);

        if ($this->isPuzzleKeptSecret->byId($puzzle->id->toString())) {
            throw new PuzzleIsStillSecret($puzzle->id->toString());
        }

        $moderator = $this->playerRepository->get($message->decidedById);

        // The puzzle was fixed meanwhile - the moderator looked at times of another piece count
        if ($puzzle->piecesCount !== $message->seenPiecesCount) {
            throw new SuspiciousTimeCaseChanged();
        }

        $confirmation = $this->suspiciousTimePuzzleConfirmationRepository->find($puzzle->id->toString());
        $previous = $confirmation !== null && $confirmation->appliesTo($puzzle->piecesCount) ? $confirmation->slowThreshold : null;

        if ($previous === $message->slowThreshold) {
            return;
        }

        $now = $this->clock->now();

        if ($confirmation === null) {
            $this->suspiciousTimePuzzleConfirmationRepository->save(new SuspiciousTimePuzzleConfirmation(
                puzzle: $puzzle,
                piecesCount: $puzzle->piecesCount,
                confirmedById: $moderator->id,
                confirmedAt: $now,
                slowThreshold: $message->slowThreshold,
            ));
        } else {
            $confirmation->changeSlowThreshold($puzzle->piecesCount, $message->slowThreshold, $moderator->id, $now);
        }

        $this->suspiciousTimeDecisionRecorder->recordAboutPuzzle(
            $message->slowThreshold !== null ? SuspiciousTimeDecisionKind::SlowThresholdSet : SuspiciousTimeDecisionKind::SlowThresholdRemoved,
            $puzzle,
            $moderator,
            details: [
                'slow_threshold' => $message->slowThreshold,
                'previous_slow_threshold' => $previous,
            ],
        );
    }
}
