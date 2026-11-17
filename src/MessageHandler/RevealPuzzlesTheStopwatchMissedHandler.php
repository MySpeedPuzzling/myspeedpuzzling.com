<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\StopwatchMissedRevealChanged;
use SpeedPuzzling\Web\Message\RevealPuzzlesTheStopwatchMissed;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Services\SecretPuzzleHides;
use SpeedPuzzling\Web\Services\SecretRevealPreview;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Reveals the round's puzzles the stopwatch start never revealed (competition toolkit G3) - each row exactly like "Reveal
 * now" (CompetitionRoundPuzzle::revealNow(), RevealRoundPuzzleNowHandler), all of them or none: under the lock order of
 * SecretPuzzleHides::lockRoundsForChange() (the round FOR NO KEY UPDATE, its secret puzzles), the list is computed again
 * and compared with the confirmed one, so a puzzle added, a reveal changed or another round's hide changed meanwhile
 * reveals nothing (StopwatchMissedRevealChanged). A list that became empty meanwhile (revealed by somebody else) is no
 * refusal - there is nothing left to reveal.
 */
#[AsMessageHandler]
readonly final class RevealPuzzlesTheStopwatchMissedHandler
{
    public function __construct(
        private CompetitionRoundRepository $roundRepository,
        private SecretPuzzleHides $secretPuzzleHides,
        private SecretRevealPreview $secretRevealPreview,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CompetitionRoundNotFound
     * @throws StopwatchMissedRevealChanged
     */
    public function __invoke(RevealPuzzlesTheStopwatchMissed $message): void
    {
        $this->secretPuzzleHides->lockRoundsForChange([$message->roundId]);

        $round = $this->roundRepository->get($message->roundId);

        if ($round->competition->id->toString() !== strtolower($message->competitionId)) {
            throw new CompetitionRoundNotFound();
        }

        $now = $this->clock->now();
        $revealed = $this->secretRevealPreview->byRevealingWhatTheStopwatchMissed($round);

        if ($revealed === []) {
            return;
        }

        if (SecretRevealPreview::hash($revealed) !== $message->confirmedHash) {
            throw new StopwatchMissedRevealChanged();
        }

        $rows = SecretRevealPreview::rowsTheStopwatchMissed($round, $now);

        foreach ($rows as $row) {
            $row->revealNow($now);
        }

        // Another round may still keep a puzzle secret on the whole site - the latest reveal wins
        $this->secretPuzzleHides->resync(...array_map(static fn (CompetitionRoundPuzzle $row) => $row->puzzle, $rows));
    }
}
