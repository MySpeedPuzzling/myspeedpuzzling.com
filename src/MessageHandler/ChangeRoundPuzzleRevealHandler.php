<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\PuzzleHiddenByHand;
use SpeedPuzzling\Web\Exceptions\RevealMomentAlreadyPassed;
use SpeedPuzzling\Web\Exceptions\RoundPuzzleAlreadyRevealed;
use SpeedPuzzling\Web\Message\ChangeRoundPuzzleReveal;
use SpeedPuzzling\Web\Query\IsPuzzleKeptSecret;
use SpeedPuzzling\Web\Repository\CompetitionRoundPuzzleRepository;
use SpeedPuzzling\Web\Services\SecretPuzzleHides;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ChangeRoundPuzzleRevealHandler
{
    public function __construct(
        private CompetitionRoundPuzzleRepository $competitionRoundPuzzleRepository,
        private SecretPuzzleHides $secretPuzzleHides,
        private IsPuzzleKeptSecret $isPuzzleKeptSecret,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws RevealMomentAlreadyPassed
     * @throws RoundPuzzleAlreadyRevealed
     * @throws PuzzleHiddenByHand
     */
    public function __invoke(ChangeRoundPuzzleReveal $message): void
    {
        $this->secretPuzzleHides->lockPuzzleOfRoundPuzzle($message->roundPuzzleId);

        $roundPuzzle = $this->competitionRoundPuzzleRepository->get($message->roundPuzzleId);
        $now = $this->clock->now();

        // A revealed puzzle is public - it is never hidden again by a reveal change
        if ($roundPuzzle->hideUntilRoundStarts && $roundPuzzle->isHiddenAt($now) === false) {
            throw new RoundPuzzleAlreadyRevealed();
        }

        // A reveal that is over already would reveal the puzzle the moment it is saved - that is "Reveal now"
        $newRevealAt = $message->revealMode->revealAt($roundPuzzle->round->startsAt, $message->scheduledAt);
        if ($message->revealMode !== RoundPuzzleReveal::Manual && ($newRevealAt === null || $newRevealAt <= $now)) {
            throw new RevealMomentAlreadyPassed();
        }

        $puzzle = $roundPuzzle->puzzle;
        $puzzleKeptSecret = $this->isPuzzleKeptSecret->byId($puzzle->id->toString());

        // A puzzle hidden by hand (a placeholder) is no round's to hide or reveal
        if ($puzzle->isImageHiddenAt($now) && $puzzleKeptSecret === false) {
            throw new PuzzleHiddenByHand();
        }

        // Kept secret by a competition (created hidden, or another round keeps it so): this row keeps it secret on
        // the whole site too
        if ($puzzleKeptSecret) {
            $roundPuzzle->keepHiddenEverywhere();
        }

        $roundPuzzle->changeReveal($message->hideMode, $message->revealMode, $message->scheduledAt);

        $this->secretPuzzleHides->resync($puzzle);
    }
}
