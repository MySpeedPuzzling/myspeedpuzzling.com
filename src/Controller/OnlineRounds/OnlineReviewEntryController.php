<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OnlineRounds;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineOrganiserApi;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineReview;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One participant of an online round's review (contract §5 `online_review_entry`, OnlineReview::detail()): their row,
 * the taps, the events in order and every evidence object with a short-lived URL (bursts with their manifest of frames)
 * - JSON for the review page, on the official results API's rules (OnlineOrganiserApi: COMPETITION_RESULTS_ENTRY and
 * ONLINE_ROUNDS_MANAGE). Opened as a page (a link shared, a reload), it leads to the review with this participant
 * selected.
 */
final class OnlineReviewEntryController extends AbstractController
{
    public function __construct(
        private readonly OnlineOrganiserApi $api,
        private readonly OnlineReview $onlineReview,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/online-kola/{roundId}/kontrola/{participantId}',
            'en' => '/en/online-rounds/{roundId}/review/{participantId}',
            'es' => '/es/online-rounds/{roundId}/review/{participantId}',
            'ja' => '/ja/online-rounds/{roundId}/review/{participantId}',
            'fr' => '/fr/online-rounds/{roundId}/review/{participantId}',
            'de' => '/de/online-rounds/{roundId}/review/{participantId}',
        ],
        name: 'online_review_entry',
        requirements: [
            'roundId' => FirstTryConflictsController::ID_REQUIREMENT,
            'participantId' => FirstTryConflictsController::ID_REQUIREMENT,
        ],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $roundId, string $participantId): Response
    {
        if (OnlineOrganiserApi::wantsJson($request) === false) {
            return $this->redirectToRoute('online_review', ['roundId' => $roundId, 'participant' => strtolower($participantId)]);
        }

        $access = $this->api->authorise($request, $roundId, review: true);

        if ($access instanceof JsonResponse) {
            return $access;
        }

        $detail = $this->onlineReview->detail($access['round'], $access['onlineRound'], $participantId);

        if ($detail === null) {
            return OfficialResultsApi::error('entry_not_found', JsonResponse::HTTP_NOT_FOUND);
        }

        return OfficialResultsApi::json($detail);
    }
}
