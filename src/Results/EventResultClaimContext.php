<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\Puzzler;

/**
 * What the claim, release and removal modals of an imported event result show
 * (docs/features/event-results-import/README.md "Claiming a spot").
 */
readonly final class EventResultClaimContext
{
    /**
     * @param list<Puzzler> $claimers the registered people of the result the viewer may see (hidden players left out)
     * @param list<EventResultSameResultCandidate> $sameResultCandidates the viewer's own results of the puzzle within a day of the event
     * @param list<string> $relayPuzzleNames a relay team's puzzles, in the order of its results
     */
    public function __construct(
        public string $timeId,
        public string $puzzleId,
        public string $puzzleName,
        public null|string $manufacturerName,
        public int $piecesCount,
        public null|int $seconds,
        public DateTimeImmutable $solvedAt,
        public bool $importedFromEvent,
        public bool $firstAttempt,
        public null|string $competitionId,
        public null|string $competitionName,
        public null|string $teamName,
        public int $puzzlersCount,
        public int $anonymousCount,
        public array $claimers,
        public array $sameResultCandidates,
        // An unfinished team (the round's time ran out): its pieces placed - $seconds is null then
        public null|int $piecesPlaced = null,
        // A single person's result (solo-and-relays.md §1): no team, $teamName is the sheet's name, one spot
        public bool $isSolo = false,
        // A relay team (solo-and-relays.md §2): its total of all its puzzles - $seconds is null, $timeId its first result
        public null|int $relayTotalSeconds = null,
        public array $relayPuzzleNames = [],
    ) {
    }

    public function isRelay(): bool
    {
        return $this->relayTotalSeconds !== null;
    }

    public function hasSpotLeft(): bool
    {
        return $this->anonymousCount > 0;
    }

    public function isClaimedByAnybody(): bool
    {
        return $this->anonymousCount < $this->puzzlersCount;
    }

    public function isClaimer(null|string $playerId): bool
    {
        return $playerId !== null && Puzzler::listContainsPlayer($this->claimers, $playerId);
    }

    public function claimer(string $playerId): null|Puzzler
    {
        foreach ($this->claimers as $claimer) {
            if ($claimer->playerId === $playerId) {
                return $claimer;
            }
        }

        return null;
    }

    /**
     * The first-try question is asked of the first claimer, and of a later one only while the result carries the tag:
     * a pair's/team's result is a first try only if it is everybody's (PuzzleSolvingTime::slotClaimedBy()).
     */
    public function asksFirstTry(): bool
    {
        // A relay team solved several puzzles at once - no first try of a puzzle is known (solo-and-relays.md §2)
        if ($this->isRelay()) {
            return false;
        }

        return $this->isClaimedByAnybody() === false || $this->firstAttempt;
    }
}
