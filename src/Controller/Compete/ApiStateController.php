<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Compete;

use SpeedPuzzling\Web\Services\LiveKit\LiveKitSettings;
use SpeedPuzzling\Web\Services\LiveKit\ParticipantStudioState;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineParticipantApi;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineRoundState;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The state a participant's page lives on (docs/features/online-competitions/implementation-plan.md §3.1 `state`,
 * OnlineRoundState) - fetched at load, on every return to the page, around the start and whenever a heartbeat's hash
 * differs. The personal link is the access right (OnlineParticipantApi).
 *
 * While live video is available (LiveKitSettings) the state also carries `studio` - the participant's latest
 * invitation to the round's studio, `{inviteId, status}` or null (ParticipantStudioState, contract phase-1b.md §4).
 * Without a media server the answer stays exactly as it was before live video.
 */
final class ApiStateController extends AbstractController
{
    public function __construct(
        private readonly OnlineParticipantApi $api,
        private readonly OnlineRoundState $onlineRoundState,
        private readonly LiveKitSettings $liveKitSettings,
        private readonly ParticipantStudioState $participantStudioState,
    ) {
    }

    #[Route(path: '/api/compete/{token}/state', name: 'compete_api_state', methods: ['GET'])]
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $refusal = $this->api->admit($request, $token);

        if ($refusal !== null) {
            return $refusal;
        }

        $access = $this->api->access($token);

        if ($access instanceof JsonResponse) {
            return $access;
        }

        $state = $this->onlineRoundState->forParticipant($access);

        if ($this->liveKitSettings->isAvailable()) {
            $state['studio'] = $this->participantStudioState->forParticipant($access);
        }

        return $this->api->json($state);
    }
}
