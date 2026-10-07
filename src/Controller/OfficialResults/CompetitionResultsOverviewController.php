<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OfficialResults;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\OfficialResultsRounds;
use SpeedPuzzling\Web\Services\OfficialResultsSubscription;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The organiser's control room during the event (docs/features/competitions-management/results-desk.md): every
 * round with its entries, results entered, qualified, seating and publication, the way into each round's live
 * entry, results desk and seating, and "Advance the qualified" across rounds. The counters follow the rounds'
 * private Mercure topics (one subscription for all of them, renewed through `official_results_competition_state`).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class CompetitionResultsOverviewController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly OfficialResultsRounds $officialResultsRounds,
        private readonly OfficialResultsSubscription $subscription,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/prehled-vysledku-udalosti/{competitionId}',
            'en' => '/en/manage-event-results/{competitionId}',
            'es' => '/es/manage-event-results/{competitionId}',
            'ja' => '/ja/manage-event-results/{competitionId}',
            'fr' => '/fr/manage-event-results/{competitionId}',
            'de' => '/de/manage-event-results/{competitionId}',
        ],
        name: 'competition_results_overview',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
    )]
    public function __invoke(string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);
        $rounds = $this->officialResultsRounds->forCompetition($competition->id);

        $response = $this->render('official_results/competition_results_overview.html.twig', [
            'competition' => $competition,
            'rounds' => $rounds,
            'mercure' => $this->subscription->forRounds(array_map(static fn ($round): string => $round->id(), $rounds)),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
