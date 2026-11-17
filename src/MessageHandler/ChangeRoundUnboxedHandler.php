<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Message\ChangeRoundUnboxed;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Services\SecretPuzzleHides;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Switches "Unboxed" on or off and re-syncs the site-wide hide of the round's secret puzzles (an unboxed round keeps the
 * picture of one it hides everywhere until its results are first published - SecretPuzzleHides::hideOf()).
 */
#[AsMessageHandler]
readonly final class ChangeRoundUnboxedHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private SecretPuzzleHides $secretPuzzleHides,
    ) {
    }

    /**
     * @throws CompetitionRoundNotFound also a round of another event than the caller authorised
     */
    public function __invoke(ChangeRoundUnboxed $message): void
    {
        // Locks the round, then its secret puzzles - waits for every other change of them, then reads fresh
        $secretPuzzleIds = $this->secretPuzzleHides->lockRoundsForChange([$message->roundId]);

        $round = $this->competitionRoundRepository->get($message->roundId);

        if ($round->competition->id->toString() !== strtolower($message->competitionId)) {
            throw new CompetitionRoundNotFound();
        }

        if ($round->unboxed === $message->unboxed) {
            return;
        }

        $round->changeUnboxed($message->unboxed);
        $this->secretPuzzleHides->resyncByIds($secretPuzzleIds);
    }
}
