<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionPageSections;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ManageCompetitionPageController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly GetCompetitionPageSections $getCompetitionPageSections,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/upravit-stranku-udalosti/{competitionId}',
            'en' => '/en/manage-event-page/{competitionId}',
            'es' => '/es/manage-event-page/{competitionId}',
            'ja' => '/ja/manage-event-page/{competitionId}',
            'fr' => '/fr/manage-event-page/{competitionId}',
            'de' => '/de/manage-event-page/{competitionId}',
        ],
        name: 'manage_competition_page',
    )]
    public function __invoke(string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);

        return $this->render('manage_competition_page.html.twig', [
            'owner_type' => 'competition',
            'owner_id' => $competitionId,
            'owner_name' => $competition->name,
            'back_url' => $this->generateUrl('edit_competition', ['competitionId' => $competitionId]),
            'view_url' => $competition->slug !== null ? $this->generateUrl('event_detail', ['slug' => $competition->slug]) : null,
            'entries' => $this->getCompetitionPageSections->forCompetition($competitionId, includeHidden: true),
        ]);
    }
}
