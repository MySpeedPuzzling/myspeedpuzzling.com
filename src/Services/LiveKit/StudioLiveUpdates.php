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
 *   LiveKit unpublishes their microphone and camera at once); they stay in the studio, watching.
 *
 * Nothing here fails a request: a failed LiveKit call is logged - at info when the participant learns it another way
 * (the heartbeat, the state), at warning when it may leave a guest on air.
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

        try {
            $this->liveKitApi->updateParticipant(
                LiveKitRoomKind::Studio->roomName($roundId),
                LiveKitIdentity::participant($participantId),
                LiveKitPermission::subscribeOnly(),
            );
        } catch (LiveKitRequestFailed $e) {
            $context = [
                'round_id' => $roundId,
                'invite_id' => $invite->id->toString(),
                'exception' => $e,
            ];

            if ($e->isNotFound()) {
                $this->logger->info('The studio guest the host thanked is not in the studio - nothing to take away', $context);

                return;
            }

            $this->logger->warning('Taking the publishing away from a studio guest failed - they may still be on air', $context);
        }
    }
}
