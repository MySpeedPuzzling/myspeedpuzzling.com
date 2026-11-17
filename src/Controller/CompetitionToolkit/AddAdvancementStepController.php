<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayLiveUpdates;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\InvalidAdvancementStep;
use SpeedPuzzling\Web\FormData\AdvancementStepFormData;
use SpeedPuzzling\Web\Message\CreateCompetitionAdvancementStep;
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
 * A new advancement step (competition toolkit A8): saved = back to the steps page, refused = the form again with 422.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class AddAdvancementStepController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly AdvancementStepForms $forms,
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionDisplayLiveUpdates $displayLiveUpdates,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/postup-udalosti/{competitionId}/novy',
            'en' => '/en/manage-event-advancement/{competitionId}/new',
            'es' => '/es/manage-event-advancement/{competitionId}/new',
            'ja' => '/ja/manage-event-advancement/{competitionId}/new',
            'fr' => '/fr/manage-event-advancement/{competitionId}/new',
            'de' => '/de/manage-event-advancement/{competitionId}/new',
        ],
        name: 'add_competition_advancement_step',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);
        $form = $this->forms->create($competitionId, new AdvancementStepFormData(), $request->getRequestUri());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->messageBus->dispatch(new CreateCompetitionAdvancementStep(
                    stepId: Uuid::uuid7(),
                    competitionId: $competitionId,
                    name: $form->getData()->name,
                    settings: $form->getData()->settings(),
                ));

                $this->addFlash('success', $this->translator->trans('advancement_step.flash.added'));

                // After the commit: the public standings' guest snapshots start a new generation (competition toolkit v2 S1)
                $this->displayLiveUpdates->standingsChanged($competitionId);
                return $this->redirectToRoute('competition_advancement_steps_manage', ['competitionId' => $competitionId], Response::HTTP_SEE_OTHER);
            } catch (InvalidAdvancementStep $refused) {
                $form->addError(new FormError($this->translator->trans($refused->translationKey())));
            }
        }

        // A refused form answers 422 (render() sets it when handed the form itself)
        $response = $this->render('competition_toolkit/advancement_step_form.html.twig', [
            'competition' => $competition,
            'form' => $form,
        ]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
