<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\RoundStopwatchRefused;
use SpeedPuzzling\Web\Query\RoundUsesStopwatchReveal;
use SpeedPuzzling\Web\Value\RoundStopwatchRefusal;

/**
 * The reveal on the stopwatch start (RoundPuzzleReveal::Stopwatch, docs/features/competitions-management/toolkit/
 * A-round-formats.md §4 + R-review-and-plan.md §2.1), shared by the round stopwatch's handlers:
 *
 * - lockIfNeeded() FIRST in the handler: a round with such a row (or a start asking to reveal) takes the secret puzzles'
 *   lock order (SecretPuzzleHides::lockRoundsForChange() - the round, its secret puzzles, entity manager cleared);
 *   every other round keeps today's statements but one cheap EXISTS.
 * - checkConfirmed() before anything changes: the list (and the stopwatch) the organiser saw is the one now.
 * - withdrawFuture() on every schedule, start, cancel, stop and reset: a reveal still ahead (a countdown with the tick)
 *   is taken back, so a moved or called-off countdown never reveals at its old moment. A moment that has passed is never
 *   taken back - a reset after a real start hides nothing again.
 * - revealAt() applies the tick at the start's moment, then resync() writes the puzzles' site-wide hide dates.
 */
readonly final class RoundStopwatchReveal
{
    public function __construct(
        private RoundUsesStopwatchReveal $roundUsesStopwatchReveal,
        private SecretPuzzleHides $secretPuzzleHides,
        private SecretRevealPreview $secretRevealPreview,
    ) {
    }

    /**
     * @return bool whether the round takes part (locked) - false = nothing of it to do
     */
    public function lockIfNeeded(string $roundId, bool $revealPuzzles): bool
    {
        if ($revealPuzzles === false && ($this->roundUsesStopwatchReveal)($roundId) === false) {
            return false;
        }

        $this->secretPuzzleHides->lockRoundsForChange([$roundId]);

        return true;
    }

    /**
     * @throws RoundStopwatchRefused
     */
    public function checkConfirmed(CompetitionRound $round, DateTimeImmutable $now, null|string $confirmedHash): void
    {
        $revealed = $this->secretRevealPreview->byStopwatchStart($round, $now);

        if ($revealed === []) {
            throw new RoundStopwatchRefused(RoundStopwatchRefusal::RevealNotAllowed);
        }

        if ($confirmedHash === null || hash_equals(SecretRevealPreview::stopwatchStartHash($revealed, $round), $confirmedHash) === false) {
            throw new RoundStopwatchRefused(RoundStopwatchRefusal::RevealChangedMeanwhile);
        }
    }

    /**
     * @return array<string, Puzzle> the puzzles whose rows changed - re-sync them
     */
    public function withdrawFuture(CompetitionRound $round, DateTimeImmutable $now): array
    {
        $changed = [];

        foreach ($round->roundPuzzles as $row) {
            if ($row->withdrawStopwatchReveal($now)) {
                $changed[$row->puzzle->id->toString()] = $row->puzzle;
            }
        }

        return $changed;
    }

    /**
     * @return array<string, Puzzle> the puzzles whose rows changed - re-sync them
     */
    public function revealAt(CompetitionRound $round, DateTimeImmutable $moment): array
    {
        $changed = [];

        foreach ($round->roundPuzzles as $row) {
            if ($row->revealAtStopwatchStart($moment)) {
                $changed[$row->puzzle->id->toString()] = $row->puzzle;
            }
        }

        return $changed;
    }

    /**
     * @param array<string, Puzzle> $puzzles
     */
    public function resync(array $puzzles): void
    {
        $this->secretPuzzleHides->resync(...array_values($puzzles));
    }
}
