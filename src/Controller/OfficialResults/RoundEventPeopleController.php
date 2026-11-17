<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OfficialResults;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetLiveResultsEventPeople;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Security\CompetitionResultsEntryVoter;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The live entry's quick add "people of the event who are no entry of this round" (GetLiveResultsEventPeople,
 * docs/features/competitions-management/live-results.md) - asked for by the page when it needs them, so the page
 * itself carries no user data (competition toolkit C1). Same readers as the round state; a referee gets no #code of a
 * player private to them, organisers every code.
 */
final class RoundEventPeopleController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly GetLiveResultsEventPeople $getLiveResultsEventPeople,
        private readonly OfficialResultsApi $api,
    ) {
    }

    #[Route(
        path: '/{_locale}/official-results/rounds/{roundId}/event-people',
        name: 'official_results_event_people',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $roundId): JsonResponse
    {
        $round = $this->roundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();
        $authorised = $this->api->authorise($request, $competitionId, write: false, attribute: CompetitionResultsEntryVoter::COMPETITION_RESULTS_ENTRY);

        if ($authorised instanceof JsonResponse) {
            return $authorised;
        }

        $organiser = $this->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        return OfficialResultsApi::json([
            'people' => $this->getLiveResultsEventPeople->notInRound($competitionId, $round->id->toString(), withPrivateCodes: $organiser),
        ]);
    }
}
