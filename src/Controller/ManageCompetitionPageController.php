<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionPageSections;
use SpeedPuzzling\Web\Security\CompetitionSeriesEditVoter;
use SpeedPuzzling\Web\Services\CompetitionDetailUrl;
use SpeedPuzzling\Web\Value\PageSectionOwner;
use SpeedPuzzling\Web\Value\PageSectionType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Page editor of an event or edition: its own content sections (docs/features/competitions-management/public-page.md).
 * An edition also lists its series' sections, which are changed on the series' page editor.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ManageCompetitionPageController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly GetCompetitionPageSections $getCompetitionPageSections,
        private readonly CompetitionDetailUrl $competitionDetailUrl,
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
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(string $competitionId): Response
    {
        $owner = PageSectionOwner::competition($competitionId);
        $this->denyAccessUnlessGranted($owner->editAttribute(), $owner->id());

        $competition = $this->getCompetitionEvents->byId($owner->id());
        $seriesId = $competition->seriesId;

        $response = $this->render('manage_page_sections.html.twig', [
            'owner' => $owner,
            'owner_name' => $competition->name,
            'back_url' => $this->generateUrl('edit_competition', ['competitionId' => $owner->id()]),
            'view_url' => $this->competitionDetailUrl->of($owner->id()),
            'sections' => $this->getCompetitionPageSections->forCompetitionEditor($owner->id()),
            'section_types' => PageSectionType::availableFor($competition->isOnline),
            'series_editor_url' => $seriesId !== null && $this->isGranted(CompetitionSeriesEditVoter::COMPETITION_SERIES_EDIT, $seriesId)
                ? $this->generateUrl('manage_series_page', ['seriesId' => $seriesId])
                : null,
        ]);

        // An organiser's tool: never cached, never indexed (the template says noindex)
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
