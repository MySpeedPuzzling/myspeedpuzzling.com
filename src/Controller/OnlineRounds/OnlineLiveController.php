<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OnlineRounds;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineLiveState;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineOrganiserApi;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The control room's state of an online round (contract §5 `online_live`, OnlineLiveState): the round's clock, every
 * participant entered with their presence, readiness, finish and latest photo, the stopwatch topic's subscription and
 * the server's time. Polled every 2-3 s while the control room is shown - two statements whatever the number of
 * participants (pinned by OnlineLiveQueryBudgetTest). JSON on the official results API's rules (OnlineOrganiserApi).
 */
final class OnlineLiveController extends AbstractController
{
    public function __construct(
        private readonly OnlineOrganiserApi $api,
        private readonly OnlineLiveState $onlineLiveState,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/online-kola/{roundId}/live.json',
            'en' => '/en/online-rounds/{roundId}/live.json',
            'es' => '/es/online-rounds/{roundId}/live.json',
            'ja' => '/ja/online-rounds/{roundId}/live.json',
            'fr' => '/fr/online-rounds/{roundId}/live.json',
            'de' => '/de/online-rounds/{roundId}/live.json',
        ],
        name: 'online_live',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $roundId): JsonResponse
    {
        $access = $this->api->authorise($request, $roundId, review: false);

        if ($access instanceof JsonResponse) {
            return $access;
        }

        return OfficialResultsApi::json($this->onlineLiveState->forRound($access['round'], $access['onlineRound']));
    }
}
