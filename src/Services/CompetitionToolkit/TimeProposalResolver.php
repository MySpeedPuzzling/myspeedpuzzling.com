<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionTimeProposal;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Repository\CompetitionTimeProposalRepository;
use SpeedPuzzling\Web\Results\RoundResultChangeOutcome;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\RoundResultChange;
use SpeedPuzzling\Web\Value\RoundResultChangeStatus;
use SpeedPuzzling\Web\Value\RoundResultField;
use SpeedPuzzling\Web\Value\TimeProposalDecisionReason;

/**
 * Time proposals inside the official results write path (competition toolkit P1, toolkit/B-qr-competitor-page.md §5.5
 * with R-review-and-plan.md §1.2) - called by RecordRoundResultsHandler in the same transaction and lock, **only when
 * the round's competition has time proposals on** (a competition without them runs no statement of this).
 *
 * - load(): one statement - the round's pending proposals and the ones the set names by id.
 * - rejectionStatus(): plans a `field: "proposal"` change (a judge's "Not right", R §1.2): applied (rejected now),
 *   unchanged (no longer pending) or refused (`proposal_not_found` - unknown, or of another entry).
 * - resolve(): after the set was written, per change in order -
 *   a `result` change with `proposalId` that went through (applied / unchanged, not a replay): its pending proposal is
 *   confirmed with the written seconds (they may differ - the judge's verdict), or rejected `not_finished` when the judge
 *   wrote no finished time; a proposal no longer pending leaves the result standing (`not_pending`);
 *   any other applied `result` change on an entry with a pending proposal: the same seconds confirm it, another result
 *   supersedes it (`result_recorded`), a cleared result leaves it alone;
 *   an applied `proposal` change rejects it with the judge's reason.
 *   A replay (the change's receipt existed before the set) touches nothing - the proposal was resolved the first time.
 *
 * Every change it says something about gets an outcome annotation `proposal: {id, status}` - status `confirmed`,
 * `rejected`, `superseded`, `not_pending`, `unknown` (no such proposal of this entry), or `pending` (the change itself
 * did not go through). Stateless (worker mode).
 */
final readonly class TimeProposalResolver
{
    public function __construct(
        private CompetitionTimeProposalRepository $proposalRepository,
    ) {
    }

    /**
     * Whether the set needs the proposals at all - only result and proposal changes can touch one
     *
     * @param list<RoundResultChange> $changes
     */
    public static function concerns(array $changes): bool
    {
        foreach ($changes as $change) {
            if ($change->field === RoundResultField::Result || $change->field === RoundResultField::Proposal) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<RoundResultChange> $changes
     * @return array<string, CompetitionTimeProposal> lower-cased id => proposal
     */
    public function load(CompetitionRound $round, array $changes): array
    {
        $ids = [];
        foreach ($changes as $change) {
            if ($change->proposalId !== null) {
                $ids[] = $change->proposalId;
            }
        }

        return $this->proposalRepository->forResolving($round, $ids);
    }

    /**
     * @param array<string, CompetitionTimeProposal> $proposals
     */
    public static function rejectionStatus(RoundResultChange $change, array $proposals): RoundResultChangeStatus|string
    {
        $proposal = self::proposalOf($change, $proposals);

        if ($proposal === null) {
            return 'proposal_not_found';
        }

        return $proposal->isPending() ? RoundResultChangeStatus::Applied : RoundResultChangeStatus::Unchanged;
    }

    /**
     * @param list<RoundResultChange> $changes
     * @param list<RoundResultChangeOutcome> $outcomes the plan's, one per change
     * @param array<string, CompetitionTimeProposal> $proposals load()'s
     * @param array<string, mixed> $replayed client change ids whose receipt existed before the set
     * @return array{outcomes: list<RoundResultChangeOutcome>, changedProposalIds: list<string>}
     */
    public function resolve(array $changes, array $outcomes, array $proposals, array $replayed, Player $actor, DateTimeImmutable $now): array
    {
        $changed = [];

        foreach ($changes as $index => $change) {
            $outcome = $outcomes[$index];
            $through = $outcome->status === RoundResultChangeStatus::Applied || $outcome->status === RoundResultChangeStatus::Unchanged;
            $replay = isset($replayed[$change->clientChangeId]);

            if ($change->field === RoundResultField::Proposal) {
                $proposal = self::proposalOf($change, $proposals);

                if ($proposal === null) {
                    $outcomes[$index] = $outcome->withProposal($change->proposalId ?? '', 'unknown');
                } elseif ($through && $replay === false && $proposal->isPending() && $change->proposalRejection !== null) {
                    $proposal->reject($change->proposalRejection, $actor, $now);
                    $changed[] = $proposal->id->toString();
                    $outcomes[$index] = $outcome->withProposal($proposal->id->toString(), $proposal->status->value);
                } else {
                    $outcomes[$index] = $outcome->withProposal($proposal->id->toString(), $replay ? $proposal->status->value : self::stateOf($proposal));
                }

                continue;
            }

            if ($change->field !== RoundResultField::Result) {
                continue;
            }

            $result = $change->to instanceof RoundEntryResult ? $change->to : RoundEntryResult::none();

            if ($change->proposalId !== null) {
                $proposal = self::proposalOf($change, $proposals);

                if ($proposal === null) {
                    $outcomes[$index] = $outcome->withProposal($change->proposalId, 'unknown');

                    continue;
                }

                if ($through && $replay === false && $proposal->isPending()) {
                    self::confirmWith($proposal, $result, $actor, $now);
                    $changed[] = $proposal->id->toString();
                    $outcomes[$index] = $outcome->withProposal($proposal->id->toString(), $proposal->status->value);

                    continue;
                }

                // A replay reports what its first sending did; a proposal another change decided is `not_pending`
                $outcomes[$index] = $outcome->withProposal($proposal->id->toString(), $through && $replay === false ? self::stateOf($proposal) : $proposal->status->value);

                continue;
            }

            // A result recorded another way while a proposal waited - only one that was written now
            if ($outcome->status !== RoundResultChangeStatus::Applied || $replay || $result->isNone()) {
                continue;
            }

            $ref = $change->entryRef()?->toString();
            $pending = null;
            foreach ($proposals as $proposal) {
                if ($proposal->isPending() && $proposal->entryRef()->toString() === $ref) {
                    $pending = $proposal;
                }
            }

            if ($pending === null) {
                continue;
            }

            if ($result->isFinished() && $result->seconds === $pending->proposedSeconds) {
                $pending->confirm($result->seconds, $actor, $now);
            } else {
                $pending->supersede(TimeProposalDecisionReason::ResultRecorded, $actor, $now);
            }

            $changed[] = $pending->id->toString();
            $outcomes[$index] = $outcome->withProposal($pending->id->toString(), $pending->status->value);
        }

        return ['outcomes' => array_values($outcomes), 'changedProposalIds' => array_values(array_unique($changed))];
    }

    /**
     * Annotation of a proposal the change did not decide: `not_pending` once decided, else `pending`
     */
    private static function stateOf(CompetitionTimeProposal $proposal): string
    {
        return $proposal->isPending() ? 'pending' : 'not_pending';
    }

    private static function confirmWith(CompetitionTimeProposal $proposal, RoundEntryResult $result, Player $actor, DateTimeImmutable $now): void
    {
        if ($result->isFinished() && $result->seconds !== null) {
            $proposal->confirm($result->seconds, $actor, $now);

            return;
        }

        $proposal->reject(TimeProposalDecisionReason::NotFinished, $actor, $now);
    }

    /**
     * The proposal the change names - only when it is of the change's entry
     *
     * @param array<string, CompetitionTimeProposal> $proposals
     */
    private static function proposalOf(RoundResultChange $change, array $proposals): null|CompetitionTimeProposal
    {
        $proposal = $change->proposalId !== null ? ($proposals[$change->proposalId] ?? null) : null;

        if ($proposal === null || $proposal->entryRef()->toString() !== $change->entryRef()?->toString()) {
            return null;
        }

        return $proposal;
    }
}
