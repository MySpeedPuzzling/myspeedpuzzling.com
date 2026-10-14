<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OnlineRounds;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Services\LiveKit\LiveKitSettings;
use SpeedPuzzling\Web\Services\LiveKit\LiveKitTokens;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineOrganiserApi;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\LiveKitRoomKind;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A moderator's or host's access token to a video room of an online round (docs/features/online-competitions/contracts/
 * phase-1b.md §3, §5 `online_video_token`): `{"room": "hall" | "studio"}` → `{url, token, identity, room}`
 * (LiveKitTokens::moderator() - `m:{playerId}`, publishing camera / microphone / screen share, the studio's admin).
 * JSON on the official results API's rules for the round's control room (OnlineOrganiserApi: ONLINE_ROUNDS_MANAGE,
 * `Content-Type: application/json`, the stateless CSRF header); `video_unavailable` 409 while LIVEKIT_* is empty or
 * the round's live video is off, `invalid_fields` 422 for another room.
 */
final class OnlineVideoTokenController extends AbstractController
{
    public function __construct(
        private readonly OnlineOrganiserApi $api,
        private readonly LiveKitSettings $liveKitSettings,
        private readonly LiveKitTokens $liveKitTokens,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/online-kola/{roundId}/video-token',
            'en' => '/en/online-rounds/{roundId}/video-token',
            'es' => '/es/online-rounds/{roundId}/video-token',
            'ja' => '/ja/online-rounds/{roundId}/video-token',
            'fr' => '/fr/online-rounds/{roundId}/video-token',
            'de' => '/de/online-rounds/{roundId}/video-token',
        ],
        name: 'online_video_token',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $roundId): JsonResponse
    {
        $access = $this->api->authorise($request, $roundId, review: false, write: true);

        if ($access instanceof JsonResponse) {
            return $access;
        }

        if ($this->liveKitSettings->isAvailable() === false || $access['onlineRound']->videoEnabled === false) {
            return OfficialResultsApi::error('video_unavailable', JsonResponse::HTTP_CONFLICT);
        }

        $body = OfficialResultsApi::body($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $room = is_string($body['room'] ?? null) ? LiveKitRoomKind::tryFrom($body['room']) : null;

        if ($room === null) {
            return OfficialResultsApi::error('invalid_fields', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $profile = $this->retrieveLoggedUserProfile->getProfile();
        $name = $profile === null ? null : ($profile->playerName ?? '#' . strtoupper($profile->code));

        return OfficialResultsApi::json($this->liveKitTokens->moderator(
            $access['playerId'],
            $access['round']->id->toString(),
            $room,
            $name,
        )->toArray());
    }
}
