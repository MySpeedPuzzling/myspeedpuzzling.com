<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayLiveUpdates;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\InvalidAdvancementStep;
use SpeedPuzzling\Web\FormData\AdvancementStepFormData;
use SpeedPuzzling\Web\Message\EditCompetitionAdvancementStep;
use SpeedPuzzling\Web\Query\GetCompetitionAdvancementSteps;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Services\CompetitionToolkit\AdvancementStepForms;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * An advancement step's settings (competition toolkit A8). The voter is checked on the event of the step loaded from
 * the database, never on an id from the request alone.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EditAdvancementStepController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly AdvancementStepForms $forms,
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionDisplayLiveUpdates $displayLiveUpdates,
        private readonly TranslatorInterface $translator,
        private readonly GetCompetitionAdvancementSteps $getCompetitionAdvancementSteps,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/postup-udalosti/{competitionId}/{stepId}/upravit',
            'en' => '/en/manage-event-advancement/{competitionId}/{stepId}/edit',
            'es' => '/es/manage-event-advancement/{competitionId}/{stepId}/edit',
            'ja' => '/ja/manage-event-advancement/{competitionId}/{stepId}/edit',
            'fr' => '/fr/manage-event-advancement/{competitionId}/{stepId}/edit',
            'de' => '/de/manage-event-advancement/{competitionId}/{stepId}/edit',
        ],
        name: 'edit_competition_advancement_step',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT, 'stepId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $competitionId, string $stepId): Response
    {
        $step = $this->getCompetitionAdvancementSteps->byId($stepId);

        if ($step->competitionId !== strtolower($competitionId)) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $step->competitionId);

        $form = $this->forms->create($step->competitionId, AdvancementStepFormData::fromStep($step), $request->getRequestUri());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->messageBus->dispatch(new EditCompetitionAdvancementStep(
                    stepId: $step->id,
                    competitionId: $step->competitionId,
                    name: $form->getData()->name,
                    settings: $form->getData()->settings(),
                ));

                $this->addFlash('success', $this->translator->trans('advancement_step.flash.saved'));

                // After the commit: the public standings' guest snapshots start a new generation (competition toolkit v2 S1)
                $this->displayLiveUpdates->standingsChanged($step->competitionId);
                return $this->redirectToRoute('competition_advancement_steps_manage', ['competitionId' => $step->competitionId], Response::HTTP_SEE_OTHER);
            } catch (InvalidAdvancementStep $refused) {
                $form->addError(new FormError($this->translator->trans($refused->translationKey())));
            }
        }

        $response = $this->render('competition_toolkit/advancement_step_form.html.twig', [
            'competition' => $this->getCompetitionEvents->byId($step->competitionId),
            'form' => $form,
            'step' => $step,
        ]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
