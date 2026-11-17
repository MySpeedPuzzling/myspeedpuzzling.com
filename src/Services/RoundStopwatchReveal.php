<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\RoundStopwatchRefused;
use SpeedPuzzling\Web\Query\RoundUsesStopwatchReveal;
use SpeedPuzzling\Web\Value\RoundStopwatchRefusal;

/**
 * The reveal on the stopwatch start (RoundPuzzleReveal::Stopwatch, docs/features/competitions-management/toolkit/
 * A-round-formats.md §4 + R-review-and-plan.md §2.1), shared by the round stopwatch's handlers:
 *
 * - lockIfNeeded() FIRST in the handler: a round with a secret puzzle (or a start asking to reveal) takes the secret
 *   puzzles' lock order (SecretPuzzleHides::lockRoundsForChange() - the round, its secret puzzles, entity manager
 *   cleared); a round without one keeps today's statements but one cheap EXISTS. Read the clock after it.
 * - checkConfirmed() before anything changes: the list (and the stopwatch) the organiser saw is the one now.
 * - withdrawFuture() on every schedule, start, cancel, stop and reset: a reveal still ahead (a countdown with the tick)
 *   is taken back, so a moved or called-off countdown never reveals at its old moment. A moment that has passed is never
 *   taken back - a reset after a real start hides nothing again.
 * - revealAt() applies the tick at the start's moment, then resync() writes the puzzles' site-wide hide dates.
 * - withdrawBeforeRemoving() in every handler removing round puzzles (remove, set the list, delete a round, an event or
 *   a series): a reveal still ahead is taken back while the rows still exist.
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
     * The check runs before any lock, so it asks for any secret row of the round, not only one in `stopwatch` mode: a
     * secret row turned to that mode between the check and this action's own write is still covered by the lock. What
     * stays outside is a round with no secret row at all at the check that gets one, turns it to `stopwatch` and is
     * started with the tick (confirmed on a page showing that row) before this action writes - and a round without
     * secret puzzles keeps today's statements (review of A1).
     *
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
        return $this->withdrawFutureOf($round->roundPuzzles, $now);
    }

    /**
     * Round puzzles about to be removed (a removed puzzle, a replaced list, a deleted round, event or series): a reveal
     * still ahead from a countdown with the tick is taken back, and the puzzles' site-wide hide dates re-synced while
     * the rows still exist. With the rows gone the dates stay as they are (SecretPuzzleHides) - by then they must say
     * "hidden" (HIDDEN_UNTIL_REVEALED), never the countdown's moment: no start happened. The removing handler re-syncs
     * once more after the removal (the other rounds keeping the puzzle secret decide then).
     *
     * Call it under the change's locks (rounds, then puzzles - SecretPuzzleHides), after every check, right before the
     * removal.
     *
     * @param iterable<CompetitionRoundPuzzle> $rows
     */
    public function withdrawBeforeRemoving(iterable $rows, DateTimeImmutable $now): void
    {
        $this->resync($this->withdrawFutureOf($rows, $now));
    }

    /**
     * @param iterable<CompetitionRoundPuzzle> $rows
     * @return array<string, Puzzle>
     */
    private function withdrawFutureOf(iterable $rows, DateTimeImmutable $now): array
    {
        $changed = [];

        foreach ($rows as $row) {
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
