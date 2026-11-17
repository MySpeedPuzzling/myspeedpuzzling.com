<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantLinking;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\ParticipantLinkLog;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Repository\ParticipantLinkLogRepository;
use SpeedPuzzling\Web\Value\ParticipantLinkAction;
use SpeedPuzzling\Web\Value\ParticipantLinkMethod;

/**
 * The one link path (docs/features/competitions-management/participant-linking.md §4, D1): every link and unlink of a
 * competition participant to a MySpeedPuzzling account goes through here - it records how (`connected_via`) and writes
 * the append-only participant_link_log. CompetitionParticipant::connect() / disconnect() are called nowhere else in src/
 * (ParticipantLinkCoverageTest); account deletion is the set-based exception (ParticipantLinksOfDeletedAccount, §11).
 *
 * Persist only, called by handlers under CompetitionParticipantsLock. The live push to the open participants sheets
 * (§5.8) is the callers' controllers' (after the commit, ParticipantsSheetLiveUpdates::changedAfterResponse()).
 */
readonly final class ParticipantLinks
{
    public function __construct(
        private ParticipantLinkLogRepository $logRepository,
    ) {
    }

    /**
     * Already linked to this player: nothing changes - the link keeps its moment and method (TimeProposalOwnership, P1F)
     * and nothing is logged.
     */
    public function link(
        CompetitionParticipant $participant,
        Player $player,
        ParticipantLinkMethod $via,
        null|Player $by,
        DateTimeImmutable $now,
    ): void {
        if ($participant->isConnectedTo($player)) {
            return;
        }

        // A row linked to somebody else is let go of first - recorded as such, the log reads as what happened
        if ($participant->player !== null) {
            $this->unlink($participant, ParticipantLinkAction::Unlinked, $by, $now);
        }

        $this->connect($participant, $player, $via, $by, $now);

        // Package L2 adds here (§4 steps 3-4): pending requests on the row superseded / approved, the player's own
        // pending request in the event superseded, and ParticipantListedYou for `organiser` / `import` links by somebody
        // else on a publicly visible event
    }

    /**
     * The player's own self-joined row they left (soft-deleted, still carrying their player) comes back with "I'm going":
     * a new link - its moment is now, like the row was made today (CompetitionSelfJoin::takeOwnRow()).
     */
    public function rejoin(CompetitionParticipant $participant, Player $player, DateTimeImmutable $now): void
    {
        $this->connect($participant, $player, ParticipantLinkMethod::SelfJoin, $player, $now);
    }

    /**
     * @param ParticipantLinkAction $why unlinked | not_me | not_this_person | account_deleted
     */
    public function unlink(
        CompetitionParticipant $participant,
        ParticipantLinkAction $why,
        null|Player $by,
        DateTimeImmutable $now,
    ): void {
        $player = $participant->player;

        if ($player === null) {
            return;
        }

        $participant->disconnect();
        $this->log($participant, $player, $why, null, $by, $now);
    }

    private function connect(
        CompetitionParticipant $participant,
        Player $player,
        ParticipantLinkMethod $via,
        null|Player $by,
        DateTimeImmutable $now,
    ): void {
        $participant->connect($player, $now, $via);
        $this->log($participant, $player, ParticipantLinkAction::Linked, $via, $by, $now);
    }

    private function log(
        CompetitionParticipant $participant,
        Player $player,
        ParticipantLinkAction $action,
        null|ParticipantLinkMethod $method,
        null|Player $by,
        DateTimeImmutable $now,
    ): void {
        $this->logRepository->save(new ParticipantLinkLog(
            id: Uuid::uuid7(),
            competitionId: $participant->competition->id,
            participantId: $participant->id,
            participantName: $participant->name,
            playerId: $player->id,
            playerName: $player->name ?? $player->code,
            action: $action,
            method: $method,
            byPlayerId: $by?->id,
            at: $now,
        ));
    }
}
