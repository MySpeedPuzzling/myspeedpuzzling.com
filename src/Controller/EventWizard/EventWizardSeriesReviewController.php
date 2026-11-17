<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\EventWizard;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\PublishEventWizardSeries;
use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use SpeedPuzzling\Web\Query\GetEventWizardSeries;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Results\EventWizardProgress;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardSeriesAccess;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardStep;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The series path's "Review" (W-creation-wizard-research.md §3.9, W3): the series and the list of its dates with their
 * round (start in its zone, who competes, the time limit), then **Publish** (PublishEventWizardSeries - the series and
 * the draft dates it was set up with) → the series' management page, or **Keep as draft** → the series' page.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EventWizardSeriesReviewController extends AbstractController
{
    public function __construct(
        private readonly EventWizardSeriesAccess $seriesAccess,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly GetCompetitionSeries $getCompetitionSeries,
        private readonly GetEventWizardSeries $getEventWizardSeries,
        private readonly CompetitionSeriesRepository $seriesRepository,
    ) {
    }

    #[Route(
        path: '/{_locale}/new-event/series/{seriesId}/review',
        name: 'event_wizard_series_review',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'seriesId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $seriesId): Response
    {
        $progress = $this->seriesAccess->draft($seriesId, EventWizardStep::SeriesReview);

        if ($progress === null) {
            return $this->redirectToRoute('manage_competition_series', ['seriesId' => $seriesId]);
        }

        if ($request->isMethod('POST')) {
            if ($this->isCsrfTokenValid('event_wizard_series_review_' . $seriesId, $request->request->getString('_token')) === false) {
                return $this->page($progress, $seriesId, $this->translator->trans('event_wizard.review.invalid'));
            }

            if ($request->request->getString('action') === 'publish') {
                // An admin's own publish puts nothing in front of the admins
                $this->messageBus->dispatch(new PublishEventWizardSeries($seriesId, notifyAdmin: $this->isGranted(AdminAccessVoter::ADMIN_ACCESS) === false));

                $series = $this->seriesRepository->get($seriesId);
                $approved = $series->isApproved() && $series->isRejected() === false;
                $this->addFlash('success', $this->translator->trans($approved ? 'drafts_core.flash.published' : 'drafts_core.flash.published_waiting'));

                return $this->redirectToRoute('manage_competition_series', ['seriesId' => $seriesId], Response::HTTP_SEE_OTHER);
            }

            $this->addFlash('success', $this->translator->trans('organizer_tools.flash.saved_as_draft'));
            $slug = $this->seriesRepository->get($seriesId)->slug;

            return $slug !== null
                ? $this->redirectToRoute('competition_series_detail', ['slug' => $slug], Response::HTTP_SEE_OTHER)
                : $this->redirectToRoute('manage_competition_series', ['seriesId' => $seriesId], Response::HTTP_SEE_OTHER);
        }

        return $this->page($progress, $seriesId);
    }

    private function page(EventWizardProgress $progress, string $seriesId, null|string $error = null): Response
    {
        $series = $this->seriesRepository->get($seriesId);

        return $this->render('event_wizard/series_review.html.twig', [
            'progress' => $progress,
            'step' => EventWizardStep::SeriesReview,
            'series' => $this->getCompetitionSeries->byId($seriesId),
            'editions' => $this->getEventWizardSeries->editions($seriesId),
            'approved_at_once' => $series->isApproved() && $series->isRejected() === false,
            'error' => $error,
        ], new Response(status: $error !== null ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
