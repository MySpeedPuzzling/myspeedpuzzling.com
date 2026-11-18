<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * A linked member of the entry changes the puzzle choice of their pending time proposal (competition toolkit v2 C2,
 * design-v2 competitors-splits.md §2.2): "Change puzzles" / "Tell the judge which puzzle" on the competitor page -
 * ChangeTimeProposalPuzzlesHandler answers with a TimeProposalAnswer. Under the event's participants lock like the
 * proposal, its undo and the judges' writes, so a choice never lands on a proposal a judge decided meanwhile.
 */
readonly final class ChangeTimeProposalPuzzles implements SerializedByLock
{
    /**
     * @param null|non-empty-list<array{position: int, label: string}> $puzzles the places the phone shows
     *     (ProposalPuzzleChoice::fromWire()), null = clear the choice
     */
    public function __construct(
        // The event the controller found the proposal in
        public string $competitionId,
        public string $proposalId,
        public string $actingPlayerId,
        public null|array $puzzles,
        // When the phone made this choice (its server-synced clock) - an older one than the stored never overwrites it
        public null|int $chosenAtMs = null,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
