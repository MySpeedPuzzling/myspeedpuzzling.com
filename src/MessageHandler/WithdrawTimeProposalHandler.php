<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\WithdrawTimeProposal;
use SpeedPuzzling\Web\Repository\CompetitionTimeProposalRepository;
use SpeedPuzzling\Web\Results\TimeProposalAnswer;
use SpeedPuzzling\Web\Services\CompetitionToolkit\TimeProposalOwnership;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Undo" of a pending time proposal (competition toolkit P1): only a linked member of its entry with a link older than
 * the round's start (TimeProposalOwnership - the same rule as proposing, so nobody linked at the venue withdraws
 * somebody else's time). A proposal a judge decided meanwhile is answered `known` with its state - never an error.
 * An unknown id, or one of another event, is `proposal_not_found`.
 */
#[AsMessageHandler]
readonly final class WithdrawTimeProposalHandler
{
    public function __construct(
        private CompetitionTimeProposalRepository $proposalRepository,
        private TimeProposalOwnership $ownership,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(WithdrawTimeProposal $message): TimeProposalAnswer
    {
        $proposal = $this->proposalRepository->find($message->proposalId);

        if ($proposal === null || $proposal->round->competition->id->toString() !== strtolower($message->competitionId)) {
            return TimeProposalAnswer::refused('proposal_not_found', '');
        }

        $round = $proposal->round;
        $roundId = $round->id->toString();
        $entry = $proposal->participantRound ?? $proposal->team;
        assert($entry !== null);

        $proposer = $this->ownership->proposerOf($round, $entry, $message->actingPlayerId);

        if (is_string($proposer)) {
            return TimeProposalAnswer::refused($proposer, $roundId);
        }

        if ($proposal->isPending() === false) {
            return TimeProposalAnswer::known($proposal->id->toString(), $roundId);
        }

        $proposal->withdraw($this->clock->now());

        return TimeProposalAnswer::withdrawn($proposal->id->toString(), $roundId);
    }
}
