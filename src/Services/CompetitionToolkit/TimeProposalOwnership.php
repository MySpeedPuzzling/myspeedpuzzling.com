<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionTeamRepository;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * Who may propose a time for a round entry, or withdraw its proposal (competition toolkit P1, R-review-and-plan.md
 * §2.4 "Proposals: anti-cheat and ownership"): the signed-in player linked to a participant of the entry - the solo
 * entrant, or any member of the pair/team - who is not removed and is going (not waiting on the waitlist).
 *
 * **The link must be older than the round's start** (the stopwatch start - for a scheduled start the scheduled moment;
 * a round never started: any link). Connecting to a friend's unlinked participant at the venue (anybody may connect
 * to an unconnected participant) and proposing their time is refused (`linked_after_start`). A link without a moment
 * (older data, before connections were dated) counts as an old one. Stateless (worker mode).
 */
final readonly class TimeProposalOwnership
{
    public const string NOT_YOUR_ENTRY = 'not_your_entry';
    public const string LINKED_AFTER_START = 'linked_after_start';
    public const string ENTRY_NOT_IN_ROUND = 'entry_not_in_round';

    public function __construct(
        private CompetitionParticipantRoundRepository $participantRoundRepository,
        private CompetitionTeamRepository $teamRepository,
    ) {
    }

    /**
     * The entry of the round the ref names - null when it is no entry of this round (another round's, gone, a person
     * row of a pair/team round).
     */
    public function entryOf(CompetitionRound $round, RoundEntryRef $ref): null|CompetitionParticipantRound|CompetitionTeam
    {
        if ($ref->isTeam()) {
            $team = $this->teamRepository->find($ref->id);

            return $team !== null && $round->category !== RoundCategory::Solo && $team->round->id->equals($round->id) ? $team : null;
        }

        $participantRound = $this->participantRoundRepository->find($ref->id);

        return $participantRound !== null
            && $round->category === RoundCategory::Solo
            && $participantRound->team === null
            && $participantRound->round->id->equals($round->id)
            ? $participantRound
            : null;
    }

    /**
     * The participant of the entry the player stands for, or the refusal (NOT_YOUR_ENTRY / LINKED_AFTER_START).
     */
    public function proposerOf(CompetitionRound $round, CompetitionParticipantRound|CompetitionTeam $entry, string $playerId): CompetitionParticipant|string
    {
        $participants = $entry instanceof CompetitionTeam
            ? array_map(static fn (CompetitionParticipantRound $row): CompetitionParticipant => $row->participant, $this->participantRoundRepository->findByTeam($entry))
            : [$entry->participant];

        $linkedLate = false;

        foreach ($participants as $participant) {
            if ($participant->player === null || $participant->player->id->toString() !== strtolower($playerId)) {
                continue;
            }

            if ($participant->isDeleted() || $participant->isGoing() === false) {
                continue;
            }

            $startedAt = $round->stopwatchStartedAt;

            if ($startedAt !== null && $participant->connectedAt !== null && $participant->connectedAt >= $startedAt) {
                $linkedLate = true;

                continue;
            }

            return $participant;
        }

        return $linkedLate ? self::LINKED_AFTER_START : self::NOT_YOUR_ENTRY;
    }
}
