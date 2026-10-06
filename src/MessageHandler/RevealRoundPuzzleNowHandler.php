<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\RevealRoundPuzzleNow;
use SpeedPuzzling\Web\Repository\CompetitionRoundPuzzleRepository;
use SpeedPuzzling\Web\Services\SecretPuzzleHides;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RevealRoundPuzzleNowHandler
{
    public function __construct(
        private CompetitionRoundPuzzleRepository $competitionRoundPuzzleRepository,
        private SecretPuzzleHides $secretPuzzleHides,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RevealRoundPuzzleNow $message): void
    {
        $roundPuzzle = $this->competitionRoundPuzzleRepository->get($message->roundPuzzleId);

        $roundPuzzle->revealNow($this->clock->now());

        // Another round may still keep the puzzle secret on the whole site - the latest reveal wins
        $this->secretPuzzleHides->resync($roundPuzzle->puzzle);
    }
}
