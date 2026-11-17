<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * "Undo" of a pending time proposal by a linked member of its entry (competition toolkit P1) - pending → withdrawn;
 * a proposal no longer pending is answered with its state (WithdrawTimeProposalHandler, TimeProposalAnswer).
 * Under the event's participants lock, like the proposal and the judges' writes.
 */
readonly final class WithdrawTimeProposal implements SerializedByLock
{
    public function __construct(
        // The event the controller found the proposal in
        public string $competitionId,
        public string $proposalId,
        public string $actingPlayerId,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
