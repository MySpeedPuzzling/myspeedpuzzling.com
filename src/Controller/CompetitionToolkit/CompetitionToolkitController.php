<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The competition toolkit's one settings hub of an event (docs/features/competitions-management/toolkit/
 * R-review-and-plan.md §1.4): a card per area - competitor pages & QR, displays & overlays, standings, round formats &
 * reveals, judges. Each toolkit package fills its own card partial (templates/competition_toolkit/_card_*.html.twig).
 * Only for whom COMPETITION_TOOLKIT_MANAGE grants (admins; organisers while COMPETITION_TOOLKIT_FOR_ORGANISERS is on).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class CompetitionToolkitController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly GetCompetitionRoundsForManagement $getCompetitionRoundsForManagement,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/nastroje-udalosti/{competitionId}',
            'en' => '/en/manage-event-toolkit/{competitionId}',
            'es' => '/es/manage-event-toolkit/{competitionId}',
            'ja' => '/ja/manage-event-toolkit/{competitionId}',
            'fr' => '/fr/manage-event-toolkit/{competitionId}',
            'de' => '/de/manage-event-toolkit/{competitionId}',
        ],
        name: 'competition_toolkit',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $competitionId);

        $response = $this->render('competition_toolkit/hub.html.twig', [
            'competition' => $this->getCompetitionEvents->byId($competitionId),
            'rounds' => $this->getCompetitionRoundsForManagement->ofCompetition($competitionId),
        ]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
