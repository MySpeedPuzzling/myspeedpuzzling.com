<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\LiveKit;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\StudioInvite;
use SpeedPuzzling\Web\Exceptions\LiveKitRequestFailed;
use SpeedPuzzling\Web\Value\LiveKitIdentity;
use SpeedPuzzling\Web\Value\LiveKitPermission;
use SpeedPuzzling\Web\Value\LiveKitRoomKind;

/**
 * What the studio's invitations tell the outside world once they are committed (contract phase-1b.md §4) - called by
 * the controllers after dispatch() returned, never by a handler (a handler's transaction may still roll back), like the
 * Mercure publishing of OfficialResultsLiveUpdates:
 * - invited(): the heartbeat's signal (StudioInviteSignal) for as long as the invitation waits, and a data message to the
 *   participant's camera device in the hall - topic DATA_TOPIC, `{"inviteId": "…"}`;
 * - answered(): the signal goes;
 * - ended(): the signal goes, and a guest who was on air loses their publishing in the studio (UpdateParticipant -
 *   LiveKit unpublishes their microphone and camera at once) and is removed from it (RemoveParticipant, contract
 *   phase-1b.md §8: a studio token alone never puts anybody back on air);
 * - removedFromStudio(): somebody of a participant's (`p:…`) joined the studio without a joined invitation - a stale
 *   studio token, a second screen of a guest the host thanked - and is removed at once (the webhook,
 *   LiveKitWebhookController), logged at info.
 *
 * Nothing here fails a request: a failed LiveKit call is logged - at info when the participant learns it another way
 * (the heartbeat, the state) or nobody stays on air, at warning when it may leave a guest on air.
 */
final readonly class StudioLiveUpdates
{
    public const string DATA_TOPIC = 'studio';

    public function __construct(
        private LiveKitApi $liveKitApi,
        private StudioInviteSignal $studioInviteSignal,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function invited(StudioInvite $invite): void
    {
        $roundId = $invite->round->id->toString();
        $participantId = $invite->participant->id->toString();
        $inviteId = $invite->id->toString();

        $this->studioInviteSignal->raise(
            $roundId,
            $participantId,
            $inviteId,
            $invite->expiresAt()->getTimestamp() - $this->clock->now()->getTimestamp(),
        );

        try {
            $this->liveKitApi->sendData(
                LiveKitRoomKind::Hall->roomName($roundId),
                json_encode(['inviteId' => $inviteId], JSON_THROW_ON_ERROR),
                [LiveKitIdentity::participant($participantId)],
                self::DATA_TOPIC,
            );
        } catch (LiveKitRequestFailed $e) {
            $this->logger->info('The studio invitation did not reach the participant as a data message - their heartbeat brings it', [
                'round_id' => $roundId,
                'invite_id' => $inviteId,
                'exception' => $e,
            ]);
        }
    }

    public function answered(StudioInvite $invite): void
    {
        $this->studioInviteSignal->clear($invite->round->id->toString(), $invite->participant->id->toString(), $invite->id->toString());
    }

    public function ended(StudioInvite $invite, bool $wasOnAir): void
    {
        $roundId = $invite->round->id->toString();
        $participantId = $invite->participant->id->toString();

        $this->studioInviteSignal->clear($roundId, $participantId, $invite->id->toString());

        if ($wasOnAir === false) {
            return;
        }

        $room = LiveKitRoomKind::Studio->roomName($roundId);
        $identity = LiveKitIdentity::participant($participantId);
        $context = [
            'round_id' => $roundId,
            'invite_id' => $invite->id->toString(),
        ];

        // Off air at once for everybody watching - their tracks are unpublished - then out of the studio
        $revoked = null;

        try {
            $this->liveKitApi->updateParticipant($room, $identity, LiveKitPermission::subscribeOnly());
        } catch (LiveKitRequestFailed $e) {
            if ($e->isNotFound()) {
                $this->logger->info('The studio guest the host thanked is not in the studio - nothing to take away', [...$context, 'exception' => $e]);

                return;
            }

            $revoked = $e;
        }

        try {
            $this->liveKitApi->removeParticipant($room, $identity);
        } catch (LiveKitRequestFailed $e) {
            if ($e->isNotFound()) {
                $this->logger->info('The studio guest the host thanked left the studio meanwhile', [...$context, 'exception' => $e]);

                return;
            }

            if ($revoked !== null) {
                $this->logger->warning('Taking the publishing away from a studio guest and removing them failed - they may still be on air', [...$context, 'exception' => $e]);

                return;
            }

            $this->logger->info('Removing a studio guest the host thanked failed - they can no longer publish, they may still watch', [...$context, 'exception' => $e]);

            return;
        }

        if ($revoked !== null) {
            $this->logger->info('Taking the publishing away from a studio guest failed - they were removed from the studio anyway', [...$context, 'exception' => $revoked]);
        }
    }

    /**
     * Somebody of a participant's (`p:{participantId}` or its `:screen`) in the round's studio without a joined
     * invitation: removed at once. Called by the webhook after the presence was recorded (committed).
     */
    public function removedFromStudio(string $roundId, string $identity): void
    {
        $context = [
            'round_id' => $roundId,
            'identity' => $identity,
        ];

        try {
            $this->liveKitApi->removeParticipant(LiveKitRoomKind::Studio->roomName($roundId), $identity);
        } catch (LiveKitRequestFailed $e) {
            if ($e->isNotFound()) {
                $this->logger->info('A participant in the studio without a joined invitation left before being removed', [...$context, 'exception' => $e]);

                return;
            }

            $this->logger->warning('Removing a participant from the studio without a joined invitation failed - they may be on air', [...$context, 'exception' => $e]);

            return;
        }

        $this->logger->info('A participant joined the studio without a joined invitation - removed', $context);
    }
}
