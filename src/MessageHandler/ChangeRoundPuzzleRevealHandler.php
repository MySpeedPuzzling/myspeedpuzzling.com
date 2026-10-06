<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\ChangeRoundPuzzleReveal;
use SpeedPuzzling\Web\Repository\CompetitionRoundPuzzleRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ChangeRoundPuzzleRevealHandler
{
    public function __construct(
        private CompetitionRoundPuzzleRepository $competitionRoundPuzzleRepository,
    ) {
    }

    public function __invoke(ChangeRoundPuzzleReveal $message): void
    {
        $roundPuzzle = $this->competitionRoundPuzzleRepository->get($message->roundPuzzleId);

        $roundPuzzle->changeReveal($message->hideMode, $message->revealMode, $message->scheduledAt);
    }
}
