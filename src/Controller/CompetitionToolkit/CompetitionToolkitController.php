<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\FormData\CompetitorPageSettingsFormData;
use SpeedPuzzling\Web\FormType\CompetitorPageSettingsFormType;
use SpeedPuzzling\Web\Message\ChangeCompetitorPageSettings;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use SpeedPuzzling\Web\Query\GetCompetitorPageSettings;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The competition toolkit's one settings hub of an event (docs/features/competitions-management/toolkit/
 * R-review-and-plan.md §1.4): a card per area - competitor pages & QR, displays & overlays, standings, round formats &
 * reveals, judges. Each toolkit package fills its own card partial (templates/competition_toolkit/_card_*.html.twig).
 * Only for whom COMPETITION_TOOLKIT_MANAGE grants (admins; organisers while COMPETITION_TOOLKIT_FOR_ORGANISERS is on).
 *
 * The cards' settings forms post back here (each form under its own name): saved = redirect back, refused = the hub
 * again with 422 (Turbo drops a 200 answer to a full-page POST).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class CompetitionToolkitController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly GetCompetitionRoundsForManagement $getCompetitionRoundsForManagement,
        private readonly GetCompetitorPageSettings $getCompetitorPageSettings,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
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
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);

        // Competitor pages & QR are for in-person events (an online round has its own finish flow)
        $competitorPagesForm = null;

        if ($competition->isOnline === false) {
            $competitorPagesForm = $this->createForm(
                CompetitorPageSettingsFormType::class,
                CompetitorPageSettingsFormData::fromSettings($this->getCompetitorPageSettings->of($competitionId)),
                ['action' => $request->getRequestUri()],
            );
            $competitorPagesForm->handleRequest($request);

            if ($competitorPagesForm->isSubmitted() && $competitorPagesForm->isValid()) {
                $this->messageBus->dispatch(new ChangeCompetitorPageSettings(
                    $competitionId,
                    $competitorPagesForm->getData()->toSettings(),
                ));

                $this->addFlash('success', $this->translator->trans('competitor_page.settings.saved'));

                return $this->redirect($request->getRequestUri());
            }
        }

        // A refused form answers 422 (render() sets it when handed the form itself)
        $response = $this->render('competition_toolkit/hub.html.twig', [
            'competition' => $competition,
            'rounds' => $this->getCompetitionRoundsForManagement->ofCompetition($competitionId),
            'competitor_pages_form' => $competitorPagesForm,
        ]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
