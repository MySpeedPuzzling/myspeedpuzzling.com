<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Event-day check-in of an event that manages registration (docs/features/competitions-management/registration.md) -
 * the event's maintainers only, and only while registration is managed (a 404 otherwise). Names and payment states of
 * the participants: never indexed, never stored by a browser or a proxy.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class CompetitionCheckInController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/prezence-udalosti/{competitionId}',
            'en' => '/en/event-check-in/{competitionId}',
            'es' => '/es/event-check-in/{competitionId}',
            'ja' => '/ja/event-check-in/{competitionId}',
            'fr' => '/fr/event-check-in/{competitionId}',
            'de' => '/de/event-check-in/{competitionId}',
        ],
        name: 'competition_check_in',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
    )]
    public function __invoke(string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);

        if ($competition->registrationManaged === false) {
            throw $this->createNotFoundException();
        }

        $response = $this->render('competition_check_in.html.twig', [
            'competition' => $competition,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
