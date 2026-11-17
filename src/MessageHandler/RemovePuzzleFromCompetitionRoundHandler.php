<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\RemovePuzzleFromCompetitionRound;
use SpeedPuzzling\Web\Repository\CompetitionRoundPuzzleRepository;
use SpeedPuzzling\Web\Exceptions\RoundPuzzleFormatRefused;
use SpeedPuzzling\Web\Exceptions\SecretPuzzlesWouldBeRevealed;
use SpeedPuzzling\Web\Value\RoundPuzzleFormat;
use SpeedPuzzling\Web\Services\CompetitionToolkit\PickRoundRecords;
use SpeedPuzzling\Web\Services\RoundStopwatchReveal;
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
        private RoundStopwatchReveal $roundStopwatchReveal,
        private ClockInterface $clock,
        private PickRoundRecords $pickRoundRecords,
    ) {
    }

    /**
     * @throws RoundPuzzleFormatRefused a `pick` round would keep fewer puzzles than its entries pick, or an entry's
     *                                  per-puzzle record names the puzzle
     * @throws SecretPuzzlesWouldBeRevealed
     */
    public function __invoke(RemovePuzzleFromCompetitionRound $message): void
    {
        $this->secretPuzzleHides->lockRoundPuzzle($message->roundPuzzleId);

        $roundPuzzle = $this->competitionRoundPuzzleRepository->get($message->roundPuzzleId);
        $round = $roundPuzzle->round;

        // A `pick` round keeps at least K puzzles (competition toolkit A2) - the organiser lowers K first. The round is
        // locked FOR SHARE above, so a format change (FOR NO KEY UPDATE) waits for this removal or the other way round
        if ($round->puzzleFormat === RoundPuzzleFormat::Pick) {
            $round->assertKeepsEnoughPuzzles($round->roundPuzzles->count() - 1);

            // A puzzle an entry's per-puzzle record names stays (competition toolkit A3) - checked with its row locked
            // FOR UPDATE, so a record being written now (FOR KEY SHARE) is committed first or no longer finds it
            $this->pickRoundRecords->lockForRemoval([$message->roundPuzzleId]);
            $entries = $this->pickRoundRecords->countEntriesNaming($round->id->toString(), [$message->roundPuzzleId]);

            if ($entries > 0) {
                throw new RoundPuzzleFormatRefused(RoundPuzzleFormatRefused::PUZZLE_IN_RESULTS, $round->puzzlesToSolve ?? 0, $round->roundPuzzles->count(), $entries);
            }
        }

        // The organiser said yes to a list before the lock - it must still be that list
        if ($message->confirmedRevealHash !== null) {
            $revealed = $this->secretRevealPreview->byRemoving([$roundPuzzle]);

            if (SecretRevealPreview::refuses($revealed, false, $message->confirmedRevealHash)) {
                throw new SecretPuzzlesWouldBeRevealed($revealed);
            }
        }

        // A countdown with "Reveal the puzzles" still ahead never reveals this puzzle at its moment once it left the
        // round: taken back while the row exists (RoundStopwatchReveal) - the round's other rows keep theirs
        if ($roundPuzzle->hideUntilRoundStarts) {
            $this->roundStopwatchReveal->withdrawBeforeRemoving([$roundPuzzle], $this->clock->now());
        }

        $roundPuzzle->recordRemoval();
        $this->competitionRoundPuzzleRepository->delete($roundPuzzle);

        // The other rounds that keep it secret decide now; with none left its dates stay - never revealed by accident.
        // Whether this reveals it is asked before (RemovePuzzleFromRoundController - the organiser confirms, re-checked above)
        $this->secretPuzzleHides->resync($roundPuzzle->puzzle);
    }
}
