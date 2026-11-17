<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantLinking;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\ParticipantLinkLog;
use SpeedPuzzling\Web\Entity\ParticipantLinkRequest;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Repository\ParticipantLinkLogRepository;
use SpeedPuzzling\Web\Repository\ParticipantLinkRequestRepository;
use SpeedPuzzling\Web\Value\LinkRequestKind;
use SpeedPuzzling\Web\Value\ParticipantLinkAction;
use SpeedPuzzling\Web\Value\ParticipantLinkMethod;

/**
 * The one link path (docs/features/competitions-management/participant-linking.md §4, D1): every link and unlink of a
 * competition participant to a MySpeedPuzzling account goes through here - it records how (`connected_via`) and writes
 * the append-only participant_link_log. CompetitionParticipant::connect() / disconnect() are called nowhere else in src/
 * (ParticipantLinkCoverageTest); account deletion is the set-based exception (ParticipantLinksOfDeletedAccount, §11).
 *
 * Requests (§3.3) are written here too - request(), decline(), withdraw(), and link()'s approval / superseding (§4 step 3).
 *
 * Persist only, called by handlers under CompetitionParticipantsLock. The live push to the open participants sheets
 * (§5.8) is the callers' controllers' (after the commit, ParticipantsSheetLiveUpdates::changedAfterResponse()).
 */
readonly final class ParticipantLinks
{
    public function __construct(
        private ParticipantLinkLogRepository $logRepository,
        private ParticipantLinkRequestRepository $requestRepository,
    ) {
    }

    /**
     * Already linked to this player: nothing changes - the link keeps its moment and method (TimeProposalOwnership, P1F)
     * and nothing is logged.
     *
     * Requests (participant-linking.md §4 step 3, D13): the player's pending request for this row is approved by the
     * link - an organiser's link of a claimant is an approval (`claim_approved`), and approving a dispute lets go of the
     * row's current player as "not this person"; everybody else's pending request on the row and the player's own pending
     * request for another row of the event are superseded.
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

        $pendingOnRow = $this->requestRepository->pendingOfParticipant($participant);
        $approved = null;

        foreach ($pendingOnRow as $request) {
            if ($request->player->id->equals($player->id)) {
                $approved = $request;
            }
        }

        if ($approved !== null && $via === ParticipantLinkMethod::Organiser) {
            $via = ParticipantLinkMethod::ClaimApproved;
        }

        // A row linked to somebody else is let go of first - recorded as such, the log reads as what happened
        if ($participant->player !== null) {
            $why = $approved !== null && $approved->kind === LinkRequestKind::Dispute && $via === ParticipantLinkMethod::ClaimApproved
                ? ParticipantLinkAction::NotThisPerson
                : ParticipantLinkAction::Unlinked;

            $this->unlink($participant, $why, $by, $now);
        }

        $this->connect($participant, $player, $via, $by, $now);

        foreach ($pendingOnRow as $request) {
            if ($request === $approved) {
                $request->approve($by, $now);
            } else {
                $this->supersede($request, $now);
            }
        }

        foreach ($this->requestRepository->pendingOfPlayer($participant->competition->id->toString(), $player->id->toString()) as $request) {
            if ($request !== $approved) {
                $this->supersede($request, $now);
            }
        }
    }

    /**
     * A claim waiting for the organiser (D4) - one pending request per player per event: the player's pending request
     * for another row is superseded by this one, a pending one for this very row is kept (asking twice changes nothing).
     */
    public function request(
        CompetitionParticipant $participant,
        Player $player,
        LinkRequestKind $kind,
        bool $releasesOwnRegistration,
        DateTimeImmutable $now,
    ): ParticipantLinkRequest {
        foreach ($this->requestRepository->pendingOfPlayer($participant->competition->id->toString(), $player->id->toString()) as $request) {
            if ($request->participant === $participant && $request->kind === $kind) {
                return $request;
            }

            $this->supersede($request, $now);
        }

        $request = new ParticipantLinkRequest(
            id: Uuid::uuid7(),
            competition: $participant->competition,
            participant: $participant,
            player: $player,
            kind: $kind,
            playerName: $player->name ?? $player->code,
            playerCode: $player->code,
            releasesOwnRegistration: $releasesOwnRegistration,
            createdAt: $now,
        );

        $this->requestRepository->save($request);
        $this->log($participant, $player, ParticipantLinkAction::Requested, null, $player, $now);

        return $request;
    }

    /**
     * The organiser's "no" (the sheet's DeclineLinkRequest): a refused pair from now on (D11) - the claimant cannot claim
     * the row again, and three refusals end their claims at the event.
     */
    public function decline(ParticipantLinkRequest $request, null|Player $by, DateTimeImmutable $now): void
    {
        if ($request->isPending() === false) {
            return;
        }

        $request->decline($by, $now);
        $this->log($request->participant, $request->player, ParticipantLinkAction::Declined, null, $by, $now);
    }

    public function withdraw(ParticipantLinkRequest $request, DateTimeImmutable $now): void
    {
        if ($request->isPending() === false) {
            return;
        }

        $request->withdraw($now);
        $this->log($request->participant, $request->player, ParticipantLinkAction::Withdrawn, null, $request->player, $now);
    }

    /**
     * A removed participant (§11): its pending requests are nobody's to decide any more - superseded, silently. A restore
     * does not revive them.
     */
    public function participantRemoved(CompetitionParticipant $participant, DateTimeImmutable $now): void
    {
        foreach ($this->requestRepository->pendingOfParticipant($participant) as $request) {
            $this->supersede($request, $now);
        }
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

    private function supersede(ParticipantLinkRequest $request, DateTimeImmutable $now): void
    {
        $request->supersede($now);
        $this->log($request->participant, $request->player, ParticipantLinkAction::Superseded, null, null, $now);
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
