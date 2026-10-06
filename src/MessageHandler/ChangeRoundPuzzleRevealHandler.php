<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\RevealMomentAlreadyPassed;
use SpeedPuzzling\Web\Exceptions\RoundPuzzleAlreadyRevealed;
use SpeedPuzzling\Web\Message\ChangeRoundPuzzleReveal;
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
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws RevealMomentAlreadyPassed
     * @throws RoundPuzzleAlreadyRevealed
     */
    public function __invoke(ChangeRoundPuzzleReveal $message): void
    {
        $roundPuzzle = $this->competitionRoundPuzzleRepository->get($message->roundPuzzleId);
        $now = $this->clock->now();

        // A revealed puzzle is public - it is never hidden again by a reveal change
        if ($roundPuzzle->hideUntilRoundStarts && $roundPuzzle->isHiddenAt($now) === false) {
            throw new RoundPuzzleAlreadyRevealed();
        }

        if (
            $message->revealMode === RoundPuzzleReveal::Scheduled
            && ($message->scheduledAt === null || $message->scheduledAt <= $now)
        ) {
            throw new RevealMomentAlreadyPassed();
        }

        // Not public right now (another round keeps it secret, or it was created hidden): this row keeps it
        // secret on the whole site too
        if ($roundPuzzle->puzzle->isImageHiddenAt($now)) {
            $roundPuzzle->keepHiddenEverywhere();
        }

        $roundPuzzle->changeReveal($message->hideMode, $message->revealMode, $message->scheduledAt);

        $this->secretPuzzleHides->resync($roundPuzzle->puzzle);
    }
}
