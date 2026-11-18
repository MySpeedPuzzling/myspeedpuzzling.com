<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\PruneDecidedTimeProposals;
use SpeedPuzzling\Web\Repository\CompetitionTimeProposalRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A competitor's time proposal (CompetitionTimeProposal) matters while it waits for a judge and for a short while after
 * the decision (the judge's card shows the last 10 minutes, a replayed change set names it - its receipt is kept 90 days
 * too). A decided one (confirmed, rejected, superseded, withdrawn) older than the retention is deleted; the official
 * result it led to lives on the entry, never on the proposal. Pending proposals are never pruned. Nothing references a
 * proposal by FK. Returns how many were deleted.
 */
#[AsMessageHandler]
readonly final class PruneDecidedTimeProposalsHandler
{
    public function __construct(
        private CompetitionTimeProposalRepository $proposalRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PruneDecidedTimeProposals $message): int
    {
        return $this->proposalRepository->deleteDecidedBefore(
            $this->clock->now()->modify("-{$message->retentionDays} days"),
        );
    }
}
