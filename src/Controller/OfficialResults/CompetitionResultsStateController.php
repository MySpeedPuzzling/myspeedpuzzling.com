<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OfficialResults;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\OfficialResultsSubscription;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The results overview's state (docs/features/competitions-management/results-desk.md "Results overview"): every round's
 * progress (RoundResultsOverview, one statement) and a fresh live updates subscription for all of them - the page
 * catches up and renews its stream with it. The event's organisers only.
 */
final class CompetitionResultsStateController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRepository $competitionRepository,
        private readonly GetRoundResultsOverview $getRoundResultsOverview,
        private readonly OfficialResultsApi $api,
        private readonly OfficialResultsSubscription $subscription,
    ) {
    }

    #[Route(
        path: '/{_locale}/official-results/competitions/{competitionId}',
        name: 'official_results_competition_state',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $competitionId): JsonResponse
    {
        $competitionId = $this->competitionRepository->get($competitionId)->id->toString();
        $authorised = $this->api->authorise($request, $competitionId, write: false);

        if ($authorised instanceof JsonResponse) {
            return $authorised;
        }

        $rounds = $this->getRoundResultsOverview->forCompetition($competitionId);

        return OfficialResultsApi::json([
            'rounds' => $rounds,
            'mercure' => $this->subscription->forRounds(array_map(static fn ($round): string => $round->roundId, $rounds)),
        ]);
    }
}
