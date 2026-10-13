<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Compete;

use SpeedPuzzling\Web\Services\OnlineRounds\OnlineParticipantApi;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineRoundState;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The state a participant's page lives on (docs/features/online-competitions/implementation-plan.md §3.1 `state`,
 * OnlineRoundState) - fetched at load, on every return to the page, around the start and whenever a heartbeat's hash
 * differs. The personal link is the access right (OnlineParticipantApi).
 */
final class ApiStateController extends AbstractController
{
    public function __construct(
        private readonly OnlineParticipantApi $api,
        private readonly OnlineRoundState $onlineRoundState,
    ) {
    }

    #[Route(path: '/api/compete/{token}/state', name: 'compete_api_state', methods: ['GET'])]
    public function __invoke(string $token): JsonResponse
    {
        $refusal = $this->api->admit($token);

        if ($refusal !== null) {
            return $refusal;
        }

        $access = $this->api->access($token);

        if ($access instanceof JsonResponse) {
            return $access;
        }

        return $this->api->json($this->onlineRoundState->forParticipant($access));
    }
}
