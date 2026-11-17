<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\EventWizard;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\PublishCompetition;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetRoundPuzzlesForManagement;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Results\EventWizardProgress;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Services\CompetitionDetailUrl;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardAccess;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardDefaults;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardExtras;
use SpeedPuzzling\Web\Services\EventWizard\GetReady\EventGetReadyChecklist;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardStep;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The event creation wizard's "Review" (W-creation-wizard-research.md §3.9): every value of the draft, the inferred ones
 * included (the round's name, zone, time limit, the secret puzzle's reveal), each with an "edit" link back to its step;
 * whether it is public at once (under an approved organization - OrganizationApprovalPolicy approved it on creation)
 * or after an admin's check; then **Publish** (PublishCompetition - the admins' e-mail on the first publish, never for
 * an admin) → the event's edit page, or **Keep as draft** → the draft's page, as the classic form does.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EventWizardReviewController extends AbstractController
{
    public function __construct(
        private readonly EventWizardAccess $access,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly CompetitionRepository $competitionRepository,
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly GetRoundPuzzlesForManagement $getRoundPuzzlesForManagement,
        private readonly CompetitionDetailUrl $competitionDetailUrl,
        private readonly EventWizardDefaults $defaults,
        private readonly EventWizardExtras $extras,
    ) {
    }

    #[Route(
        path: '/{_locale}/new-event/{competitionId}/review',
        name: 'event_wizard_review',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $this->access->player();
        $progress = $this->access->draft($competitionId, EventWizardStep::Review);

        if ($progress === null) {
            return $this->redirectToRoute('edit_competition', ['competitionId' => $competitionId]);
        }

        if ($request->isMethod('POST')) {
            if ($this->isCsrfTokenValid('event_wizard_review_' . $competitionId, $request->request->getString('_token')) === false) {
                return $this->page($progress, $competitionId, $this->translator->trans('event_wizard.review.invalid'));
            }

            if ($request->request->getString('action') === 'publish') {
                // An admin's own publish puts nothing in front of the admins
                $this->messageBus->dispatch(new PublishCompetition($competitionId, notifyAdmin: $this->isGranted(AdminAccessVoter::ADMIN_ACCESS) === false));

                $competition = $this->competitionRepository->get($competitionId);
                $approved = $competition->isApproved() && $competition->isRejected() === false;
                $this->addFlash('success', $this->translator->trans($approved ? 'drafts_core.flash.published' : 'drafts_core.flash.published_waiting'));
                // The edit page opens its "Get ready" checklist with "Published - here's what's left" (W2)
                $this->addFlash(EventGetReadyChecklist::PUBLISHED_FLASH, '1');

                return $this->redirectToRoute('edit_competition', ['competitionId' => $competitionId], Response::HTTP_SEE_OTHER);
            }

            $this->addFlash('success', $this->translator->trans('organizer_tools.flash.saved_as_draft'));

            return $this->redirect($this->competitionDetailUrl->of($competitionId), Response::HTTP_SEE_OTHER);
        }

        return $this->page($progress, $competitionId);
    }

    private function page(EventWizardProgress $progress, string $competitionId, null|string $error = null): Response
    {
        $competition = $this->competitionRepository->get($competitionId);
        $roundId = $progress->state?->singleRoundId;
        $round = $roundId !== null ? $this->competitionRoundRepository->get($roundId) : null;

        return $this->render('event_wizard/review.html.twig', [
            'progress' => $progress,
            'step' => EventWizardStep::Review,
            'competition' => $this->getCompetitionEvents->byId($competitionId),
            'round' => $round,
            'round_name_generated' => $round !== null && $round->name === $this->defaults->roundName($round->category),
            'round_puzzles' => $round !== null ? $this->getRoundPuzzlesForManagement->ofRound($round->id->toString()) : [],
            // What the Extras step switched on (W6) - listed with "edit" back to it
            'extras' => $progress->has(EventWizardStep::Extras)
                ? $this->extras->of($competitionId, $this->isGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $competitionId))
                : null,
            'approved_at_once' => $competition->isApproved() && $competition->isRejected() === false,
            'error' => $error,
        ], new Response(status: $error !== null ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
