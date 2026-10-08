<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Restructure;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Move to another series" (docs/features/organizations/README.md "Restructuring tools") - SKELETON of the foundation:
 * the route and its access rule. Workstream D builds the page (→ MoveEditionToSeries).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class MoveEditionController extends AbstractController
{
    #[Route(
        path: '/{_locale}/move-edition/{competitionId}',
        name: 'move_edition',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        throw new NotFoundHttpException();
    }
}
