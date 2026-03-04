<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\RemovePuzzleFromCompetitionRound;
use SpeedPuzzling\Web\Repository\CompetitionRoundPuzzleRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RemovePuzzleFromCompetitionRoundHandler
{
    public function __construct(
        private CompetitionRoundPuzzleRepository $competitionRoundPuzzleRepository,
    ) {
    }

    public function __invoke(RemovePuzzleFromCompetitionRound $message): void
    {
        $roundPuzzle = $this->competitionRoundPuzzleRepository->get($message->roundPuzzleId);
        $this->competitionRoundPuzzleRepository->delete($roundPuzzle);
    }
}
