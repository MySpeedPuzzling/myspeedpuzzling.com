<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Compete;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\OnlineLinkNotFound;
use SpeedPuzzling\Web\Services\LiveKit\LiveKitSettings;
use SpeedPuzzling\Web\Services\LiveKit\StudioInviteSignal;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineAccessLight;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineParticipantApi;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlinePresence;
use SpeedPuzzling\Web\Value\OnlineHeartbeat;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A participant's phone's heartbeat, every 15 s (docs/features/online-competitions/README.md §5.3, §5.6):
 * - the beat goes to the presence of their current round (OnlinePresence, redis-state) when they are entered in it -
 *   as the participant's presence only from the device holding the entry (or any while none holds it): another
 *   device's beat (a second phone, a laptop that never agreed to the photos) is kept apart, never over it;
 * - the answer `{serverNowMs, stateHash, settingsHash, roundId, takenOverBy}` is a clock sample, the hint to fetch the
 *   state again (the round clock's hash, the hash of the round's online timing settings the phone uses or the round
 *   itself differ from what the phone holds - neither hash has the round id, so it is sent too; `settingsHash`:
 *   OnlineParticipantSettings, so an organiser's change reaches a phone even when the push after the save did not),
 *   and `takenOverBy` = the device holding the entry when it is another one (the last to send `device.ready`).
 *
 * While live video is available (LiveKitSettings) the answer also carries `studioInviteId` - the participant's
 * invitation to the studio that waits for their answer, or null (contract phase-1b.md §4): a data message the phone
 * missed costs nothing. It comes from redis-state (StudioInviteSignal), never from Postgres; without a media server the
 * answer stays exactly as it was before live video.
 *
 * Postgres is not asked on the way: the link comes from OnlineAccessLight's cache (one lookup a minute per link) and
 * both hashes from its per-round cache - pinned by the heartbeat's query budget test. A presence store that is down
 * never fails a heartbeat: the clock sample and the hint still go out.
 */
final class ApiHeartbeatController extends AbstractController
{
    public function __construct(
        private readonly OnlineParticipantApi $api,
        private readonly OnlineAccessLight $onlineAccessLight,
        private readonly OnlinePresence $onlinePresence,
        private readonly LiveKitSettings $liveKitSettings,
        private readonly StudioInviteSignal $studioInviteSignal,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/api/compete/{token}/heartbeat', name: 'compete_api_heartbeat', methods: ['POST'])]
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $refusal = $this->api->admit($request, $token);

        if ($refusal !== null) {
            return $refusal;
        }

        try {
            $access = $this->onlineAccessLight->forToken($token);
        } catch (OnlineLinkNotFound) {
            return $this->api->error('unknown_link', JsonResponse::HTTP_NOT_FOUND);
        }

        $body = $this->api->body($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $heartbeat = OnlineHeartbeat::read($body);

        if ($heartbeat === null) {
            return $this->api->error('invalid_fields', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($access->roundId !== null && $access->entryId !== null) {
            try {
                $holdsEntry = $access->activeDeviceId === null || $access->activeDeviceId === $heartbeat->deviceId;
                $this->onlinePresence->beat($access->roundId, $access->participantId, $heartbeat->telemetry, $this->api->nowMs(), $holdsEntry);
            } catch (\Throwable $e) {
                $this->logger->warning('Recording an online heartbeat failed', [
                    'round_id' => $access->roundId,
                    'exception' => $e,
                ]);
            }
        }

        $takenOverBy = $access->activeDeviceId !== null && $access->activeDeviceId !== $heartbeat->deviceId
            ? $access->activeDeviceId
            : null;

        $hashes = $access->roundId === null ? null : $this->onlineAccessLight->roundHashes($access->roundId);
        $answer = [
            'stateHash' => $hashes['stateHash'] ?? null,
            'settingsHash' => $hashes['settingsHash'] ?? null,
            'roundId' => $access->roundId,
            'takenOverBy' => $takenOverBy,
        ];

        if ($this->liveKitSettings->isAvailable()) {
            $answer['studioInviteId'] = $access->roundId === null || $access->entryId === null
                ? null
                : $this->studioInviteSignal->open($access->roundId, $access->participantId);
        }

        return $this->api->json($answer);
    }
}
