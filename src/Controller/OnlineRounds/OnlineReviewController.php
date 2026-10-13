<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OnlineRounds;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotFound;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionResultsEntryVoter;
use SpeedPuzzling\Web\Security\OnlineRoundsVoter;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineOrganiserApi;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineReview;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The review of an online round (docs/features/online-competitions/README.md §5.9, contract §5 `online_review`): one
 * row per participant entered - the suggested official time, the status, the flags - and the selected participant's
 * evidence (`online_review_entry`, fetched by the page): the timeline, the finish bursts to scrub to the exact frame,
 * the reported pieces with the end photo; `C` confirms, `U` confirms unfinished (`online_review_confirm`).
 * assets/controllers/online_review_controller.js.
 *
 * COMPETITION_RESULTS_ENTRY and ONLINE_ROUNDS_MANAGE (the evidence photos stay with the event's organisers, README §8).
 * Asked with `Accept: application/json` it answers the list only (the page fetches it again after another device's
 * save) - JSON on the official results API's rules (OnlineOrganiserApi). Never cached.
 */
final class OnlineReviewController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly OnlineRoundRepository $onlineRoundRepository,
        private readonly OnlineOrganiserApi $api,
        private readonly OnlineReview $onlineReview,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/online-kola/{roundId}/kontrola',
            'en' => '/en/online-rounds/{roundId}/review',
            'es' => '/es/online-rounds/{roundId}/review',
            'ja' => '/ja/online-rounds/{roundId}/review',
            'fr' => '/fr/online-rounds/{roundId}/review',
            'de' => '/de/online-rounds/{roundId}/review',
        ],
        name: 'online_review',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $roundId): Response
    {
        if (OnlineOrganiserApi::wantsJson($request)) {
            $access = $this->api->authorise($request, $roundId, review: true);

            if ($access instanceof JsonResponse) {
                return $access;
            }

            return OfficialResultsApi::json($this->onlineReview->list($access['round'], $access['onlineRound']));
        }

        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        $round = $this->competitionRoundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();
        $this->denyAccessUnlessGranted(CompetitionResultsEntryVoter::COMPETITION_RESULTS_ENTRY, $competitionId);
        $this->denyAccessUnlessGranted(OnlineRoundsVoter::ONLINE_ROUNDS_MANAGE, $competitionId);

        $roundId = $round->id->toString();
        $onlineRound = $this->onlineRoundRepository->ofRound($roundId) ?? throw new OnlineRoundNotFound();
        // A participant to open first (the entry address opened as a page) - one of the list, or none
        $selected = $request->query->getString('participant');

        $response = $this->render('online_rounds/review.html.twig', [
            'competition' => $round->competition,
            'round' => $round,
            'online_round' => $onlineRound,
            'state' => $this->onlineReview->list($round, $onlineRound),
            'selected' => Uuid::isValid($selected) ? strtolower($selected) : null,
            // Placeholders of the entry addresses - the page puts the participant's id in their place
            'participant_placeholder' => Uuid::NIL,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
