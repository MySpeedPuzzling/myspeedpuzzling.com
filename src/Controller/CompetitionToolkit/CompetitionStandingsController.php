<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use SpeedPuzzling\Web\Query\GetCompetitionStandings;
use SpeedPuzzling\Web\Query\GetStandingResults;
use SpeedPuzzling\Web\Results\CompetitionStandingOverview;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The standings of an event (competition toolkit A6, A-round-formats.md §3.6 `competition_standings_manage`): each with
 * its live preview over every round, published or not, labelled provisional; New standing, "One per category", edit,
 * delete. Only for whom COMPETITION_TOOLKIT_MANAGE grants.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class CompetitionStandingsController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly GetCompetitionRoundsForManagement $getCompetitionRoundsForManagement,
        private readonly GetCompetitionStandings $getCompetitionStandings,
        private readonly GetStandingResults $getStandingResults,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/poradi-udalosti/{competitionId}',
            'en' => '/en/manage-event-standings/{competitionId}',
            'es' => '/es/manage-event-standings/{competitionId}',
            'ja' => '/ja/manage-event-standings/{competitionId}',
            'fr' => '/fr/manage-event-standings/{competitionId}',
            'de' => '/de/manage-event-standings/{competitionId}',
        ],
        name: 'competition_standings_manage',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);
        $rounds = $this->getCompetitionRoundsForManagement->ofCompetition($competitionId);
        $standings = $this->getCompetitionStandings->ofCompetition($competitionId);

        // "One per category": the categories that have rounds and no standing yet
        $covered = array_map(static fn (CompetitionStandingOverview $standing): RoundCategory => $standing->category, $standings);
        $missingCategories = [];

        foreach (RoundCategory::cases() as $category) {
            foreach ($rounds as $round) {
                if ($round->category === $category && !in_array($category, $covered, true)) {
                    $missingCategories[] = $category;

                    break;
                }
            }
        }

        $response = $this->render('competition_toolkit/standings.html.twig', [
            'competition' => $competition,
            'rounds' => $rounds,
            'tables' => array_map(fn (CompetitionStandingOverview $standing) => $this->getStandingResults->organiserView($standing), $standings),
            'missing_categories' => $missingCategories,
        ]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
