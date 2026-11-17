<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\DeleteCompetitionAdvancementStep;
use SpeedPuzzling\Web\Query\GetCompetitionAdvancementSteps;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Removes an advancement step (competition toolkit A8) - rounds, marks and entries stay as they are.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class DeleteAdvancementStepController extends AbstractController
{
    public const string CSRF_TOKEN_PREFIX = 'delete-advancement-step-';

    public function __construct(
        private readonly GetCompetitionAdvancementSteps $getCompetitionAdvancementSteps,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/postup-udalosti/{competitionId}/{stepId}/smazat',
            'en' => '/en/manage-event-advancement/{competitionId}/{stepId}/delete',
            'es' => '/es/manage-event-advancement/{competitionId}/{stepId}/delete',
            'ja' => '/ja/manage-event-advancement/{competitionId}/{stepId}/delete',
            'fr' => '/fr/manage-event-advancement/{competitionId}/{stepId}/delete',
            'de' => '/de/manage-event-advancement/{competitionId}/{stepId}/delete',
        ],
        name: 'delete_competition_advancement_step',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT, 'stepId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $competitionId, string $stepId): Response
    {
        $step = $this->getCompetitionAdvancementSteps->byId($stepId);

        if ($step->competitionId !== strtolower($competitionId)) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $step->competitionId);

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_PREFIX . $step->id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->messageBus->dispatch(new DeleteCompetitionAdvancementStep($step->id, $step->competitionId));

        $this->addFlash('success', $this->translator->trans('advancement_step.flash.deleted'));

        return $this->redirectToRoute('competition_advancement_steps_manage', ['competitionId' => $step->competitionId], Response::HTTP_SEE_OTHER);
    }
}
