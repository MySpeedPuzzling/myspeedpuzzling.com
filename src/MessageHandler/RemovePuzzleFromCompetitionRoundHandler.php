<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\RemovePuzzleFromCompetitionRound;
use SpeedPuzzling\Web\Repository\CompetitionRoundPuzzleRepository;
use SpeedPuzzling\Web\Exceptions\SecretPuzzlesWouldBeRevealed;
use SpeedPuzzling\Web\Services\SecretPuzzleHides;
use SpeedPuzzling\Web\Services\SecretRevealPreview;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RemovePuzzleFromCompetitionRoundHandler
{
    public function __construct(
        private CompetitionRoundPuzzleRepository $competitionRoundPuzzleRepository,
        private SecretPuzzleHides $secretPuzzleHides,
        private SecretRevealPreview $secretRevealPreview,
    ) {
    }

    public function __invoke(RemovePuzzleFromCompetitionRound $message): void
    {
        $this->secretPuzzleHides->lockRoundPuzzle($message->roundPuzzleId);

        $roundPuzzle = $this->competitionRoundPuzzleRepository->get($message->roundPuzzleId);

        // The organiser said yes to a list before the lock - it must still be that list
        if ($message->confirmedRevealHash !== null) {
            $revealed = $this->secretRevealPreview->byRemoving([$roundPuzzle]);

            if (SecretRevealPreview::refuses($revealed, false, $message->confirmedRevealHash)) {
                throw new SecretPuzzlesWouldBeRevealed($revealed);
            }
        }

        $roundPuzzle->recordRemoval();
        $this->competitionRoundPuzzleRepository->delete($roundPuzzle);

        // The other rounds that keep it secret decide now; with none left its dates stay - never revealed by accident.
        // Whether this reveals it is asked before (RemovePuzzleFromRoundController - the organiser confirms, re-checked above)
        $this->secretPuzzleHides->resync($roundPuzzle->puzzle);
    }
}
