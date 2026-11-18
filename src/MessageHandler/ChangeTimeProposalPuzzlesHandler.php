<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\ChangeTimeProposalPuzzles;
use SpeedPuzzling\Web\Repository\CompetitionTimeProposalRepository;
use SpeedPuzzling\Web\Results\TimeProposalAnswer;
use SpeedPuzzling\Web\Services\CompetitionToolkit\TimeProposalOwnership;
use SpeedPuzzling\Web\Value\ProposalPuzzleChoice;
use SpeedPuzzling\Web\Value\RoundClock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The puzzle choice of a pending time proposal (competition toolkit v2 C2, ProposalPuzzleChoice): only a linked member
 * of its entry with a link older than the round's start (TimeProposalOwnership - the same rule as proposing and
 * withdrawing). In this order:
 *
 * 1. `proposal_not_found` - an unknown id, or one of another event; `proposals_off` - the event takes no proposals
 *    (any more), like ProposeRoundTimeHandler;
 * 2. ownership: `not_your_entry`, `linked_after_start`;
 * 3. a proposal no longer pending is answered `known` with its state - never an error, never a change (the phone shows
 *    what the judge decided); so is a choice made earlier (`chosenAtMs`, the phone's synced clock) than the stored one
 *    - an outbox's late replay never overwrites a newer choice;
 * 4. `too_late` - received more than 30 min after the round's end (ProposeRoundTimeHandler::isTooLate());
 * 5. no puzzles = the choice cleared; else the places resolved against the round's puzzles as they are now -
 *    `puzzles_not_used`, `unknown_round_puzzle`, `puzzles_changed`, `puzzle_listed_twice`, `too_many_puzzles`.
 *
 * Writes the proposal row only - never the entry, never an official result: the judge's Confirm records the puzzles.
 * Nothing is published here - the controller does after the commit.
 */
#[AsMessageHandler]
readonly final class ChangeTimeProposalPuzzlesHandler
{
    public function __construct(
        private CompetitionTimeProposalRepository $proposalRepository,
        private TimeProposalOwnership $ownership,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ChangeTimeProposalPuzzles $message): TimeProposalAnswer
    {
        $proposal = $this->proposalRepository->find($message->proposalId);

        if ($proposal === null || $proposal->round->competition->id->toString() !== strtolower($message->competitionId)) {
            return TimeProposalAnswer::refused('proposal_not_found', '');
        }

        $round = $proposal->round;
        $roundId = $round->id->toString();
        $competition = $round->competition;

        // Switched off since the tap - the same rule as proposing
        if ($competition->timeProposals === false || $competition->isOnline) {
            return TimeProposalAnswer::refused('proposals_off', $roundId);
        }

        $entry = $proposal->participantRound ?? $proposal->team;
        assert($entry !== null);

        $proposer = $this->ownership->proposerOf($round, $entry, $message->actingPlayerId);

        if (is_string($proposer)) {
            return TimeProposalAnswer::refused($proposer, $roundId);
        }

        // Decided meanwhile, or a late replay of a choice older than the stored one: nothing changes, the phone shows
        // what is stored
        if ($proposal->isPending() === false || $proposal->hasNewerPuzzleChoiceThan($message->chosenAtMs)) {
            return TimeProposalAnswer::known($proposal->id->toString(), $roundId);
        }

        // The same grace as proposing: received more than 30 min after the round's end
        if (ProposeRoundTimeHandler::isTooLate($round->stopwatchStatus, $round->stopwatchStartedAt, $round->stopwatchStoppedAt, $round->minutesLimit, RoundClock::milliseconds($this->clock->now()))) {
            return TimeProposalAnswer::refused('too_late', $roundId);
        }

        if ($message->puzzles === null) {
            $proposal->choosePuzzles(null, $message->chosenAtMs);

            return TimeProposalAnswer::chosen($proposal->id->toString(), $roundId);
        }

        $choice = ProposalPuzzleChoice::resolveForRound($message->puzzles, $round);

        if (is_string($choice)) {
            return TimeProposalAnswer::refused($choice, $roundId);
        }

        $proposal->choosePuzzles($choice, $message->chosenAtMs);

        return TimeProposalAnswer::chosen($proposal->id->toString(), $roundId);
    }
}
