<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Compete;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\OnlineLinkNotFound;
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
 * - the beat goes to the presence of their current round (OnlinePresence, redis-state) when they are entered in it;
 * - the answer `{serverNowMs, stateHash, roundId, takenOverBy}` is a clock sample, the hint to fetch the state again
 *   (the round clock's hash or the round itself differ from what the phone holds - RoundClock::stateHash() has no
 *   round id, so both are sent), and `takenOverBy` = the device holding the entry when it is another one (the last
 *   to send `device.ready`).
 *
 * Postgres is not asked on the way: the link comes from OnlineAccessLight's cache (one lookup a minute per link) and
 * the hash from its per-round cache - pinned by the heartbeat's query budget test. A presence store that is down
 * never fails a heartbeat: the clock sample and the hint still go out.
 */
final class ApiHeartbeatController extends AbstractController
{
    public function __construct(
        private readonly OnlineParticipantApi $api,
        private readonly OnlineAccessLight $onlineAccessLight,
        private readonly OnlinePresence $onlinePresence,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/api/compete/{token}/heartbeat', name: 'compete_api_heartbeat', methods: ['POST'])]
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $refusal = $this->api->admit($token);

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
                $this->onlinePresence->beat($access->roundId, $access->participantId, $heartbeat->telemetry, $this->api->nowMs());
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

        return $this->api->json([
            'stateHash' => $access->roundId === null ? null : $this->onlineAccessLight->roundStateHash($access->roundId),
            'roundId' => $access->roundId,
            'takenOverBy' => $takenOverBy,
        ]);
    }
}
