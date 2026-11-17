<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OfficialResults;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetCompetitionStandings;
use SpeedPuzzling\Web\Query\GetStandingResults;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Security\CompetitionResultsEntryVoter;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A standing for the organiser tools (competition toolkit A6, A-round-formats.md §6.4 `official_results_standing_state`):
 * computed over every round of it, published or not (`provisional`, `includesUnpublished`). Organisers and referees
 * (COMPETITION_RESULTS_ENTRY) - a referee gets no profile codes of private players. Never cached. The public variant is
 * deferred (R-review-and-plan.md §3).
 */
final class StandingStateController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionStandings $getCompetitionStandings,
        private readonly GetStandingResults $getStandingResults,
        private readonly OfficialResultsApi $api,
    ) {
    }

    #[Route(
        path: '/{_locale}/official-results/standings/{standingId}',
        name: 'official_results_standing_state',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'standingId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $standingId): JsonResponse
    {
        $standing = $this->getCompetitionStandings->byId($standingId);
        $authorised = $this->api->authorise($request, $standing->competitionId, write: false, attribute: CompetitionResultsEntryVoter::COMPETITION_RESULTS_ENTRY);

        if ($authorised instanceof JsonResponse) {
            return $authorised;
        }

        $organiser = $this->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $standing->competitionId);

        return OfficialResultsApi::json($this->getStandingResults->organiserView($standing, $organiser)->toArray());
    }
}
