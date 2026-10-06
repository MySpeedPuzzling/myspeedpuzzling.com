<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\RemovePuzzleFromCompetitionRound;
use SpeedPuzzling\Web\Repository\CompetitionRoundPuzzleRepository;
use SpeedPuzzling\Web\Services\SecretPuzzleHides;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RemovePuzzleFromCompetitionRoundHandler
{
    public function __construct(
        private CompetitionRoundPuzzleRepository $competitionRoundPuzzleRepository,
        private SecretPuzzleHides $secretPuzzleHides,
    ) {
    }

    public function __invoke(RemovePuzzleFromCompetitionRound $message): void
    {
        $this->secretPuzzleHides->lockPuzzleOfRoundPuzzle($message->roundPuzzleId);

        $roundPuzzle = $this->competitionRoundPuzzleRepository->get($message->roundPuzzleId);
        $roundPuzzle->recordRemoval();
        $this->competitionRoundPuzzleRepository->delete($roundPuzzle);

        // The other rounds that keep it secret decide now; with none left its dates stay - never revealed by accident.
        // Whether this reveals it is asked before (RemovePuzzleFromRoundController - the organiser confirms)
        $this->secretPuzzleHides->resync($roundPuzzle->puzzle);
    }
}
