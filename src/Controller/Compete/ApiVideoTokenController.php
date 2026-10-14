<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Compete;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Repository\OnlineEntryRepository;
use SpeedPuzzling\Web\Repository\StudioInviteRepository;
use SpeedPuzzling\Web\Services\LiveKit\LiveKitSettings;
use SpeedPuzzling\Web\Services\LiveKit\LiveKitTokens;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineParticipantApi;
use SpeedPuzzling\Web\Value\LiveKitParticipantRole;
use SpeedPuzzling\Web\Value\LiveKitRoomKind;
use SpeedPuzzling\Web\Value\OnlineHeartbeat;
use SpeedPuzzling\Web\Value\StudioInviteStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A participant's access token to a video room of their current round (docs/features/online-competitions/contracts/
 * phase-1b.md §3, §5): `{deviceId, room: "hall" | "studio", role: "camera" | "screen"}` (role defaults to camera) →
 * `{serverNowMs, url, token, identity, room}` (LiveKitTokens::participant()). The personal link is the access right
 * (OnlineParticipantApi: rate limits, unknown links, JSON errors), then:
 * - live video must be available and on for the round - `video_unavailable` 409 (also while LIVEKIT_* is empty);
 * - the link's current round (`round_not_online` 409), the participant entered in it (`not_entered` 409);
 * - the camera role only for the device holding the entry (the last `device.ready`, OnlineEntry::$activeDeviceId) -
 *   any other device asks as a screen (`not_active_device` 409);
 * - the studio only with a joined invitation (`studio_not_joined` 409);
 * - `invalid_fields` 422 for a body that names no device, room or role.
 */
final class ApiVideoTokenController extends AbstractController
{
    public function __construct(
        private readonly OnlineParticipantApi $api,
        private readonly LiveKitSettings $liveKitSettings,
        private readonly LiveKitTokens $liveKitTokens,
        private readonly OnlineEntryRepository $onlineEntryRepository,
        private readonly StudioInviteRepository $studioInviteRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(path: '/api/compete/{token}/video-token', name: 'compete_api_video_token', methods: ['POST'])]
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $refusal = $this->api->admit($request, $token);

        if ($refusal !== null) {
            return $refusal;
        }

        // No media server: answered without the database
        if ($this->liveKitSettings->isAvailable() === false) {
            return $this->api->error('video_unavailable', JsonResponse::HTTP_CONFLICT);
        }

        $access = $this->api->access($token);

        if ($access instanceof JsonResponse) {
            return $access;
        }

        $body = $this->api->body($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $deviceId = OnlineHeartbeat::deviceId($body['deviceId'] ?? null);
        $room = is_string($body['room'] ?? null) ? LiveKitRoomKind::tryFrom($body['room']) : null;
        $role = $body['role'] ?? LiveKitParticipantRole::Camera->value;
        $role = is_string($role) ? LiveKitParticipantRole::tryFrom($role) : null;

        if ($deviceId === null || $room === null || $role === null) {
            return $this->api->error('invalid_fields', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $round = $access->round;

        if ($round === null || $access->onlineRound === null) {
            return $this->api->error('round_not_online', JsonResponse::HTTP_CONFLICT);
        }

        if ($access->onlineRound->videoEnabled === false) {
            return $this->api->error('video_unavailable', JsonResponse::HTTP_CONFLICT);
        }

        if ($access->entry === null) {
            return $this->api->error('not_entered', JsonResponse::HTTP_CONFLICT);
        }

        if ($role === LiveKitParticipantRole::Camera && $this->onlineEntryRepository->ofEntry($access->entry->id->toString())?->activeDeviceId !== $deviceId) {
            return $this->api->error('not_active_device', JsonResponse::HTTP_CONFLICT);
        }

        if ($room === LiveKitRoomKind::Studio) {
            $invite = $this->studioInviteRepository->latestOf($round->id->toString(), $access->participant->id->toString());

            if ($invite?->statusAt($this->clock->now()) !== StudioInviteStatus::Joined) {
                return $this->api->error('studio_not_joined', JsonResponse::HTTP_CONFLICT);
            }
        }

        return $this->api->json($this->liveKitTokens->participant($access, $deviceId, $room, $role)->toArray());
    }
}
