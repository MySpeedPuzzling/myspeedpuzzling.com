<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Restructure;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Move to another event or edition" (docs/features/organizations/README.md "Restructuring tools") - SKELETON of the
 * foundation: the route and its access rule (whoever can edit the round's competition). Workstream D builds the page
 * (→ MoveRoundToCompetition).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class MoveCompetitionRoundController extends AbstractController
{
    public function __construct(
        readonly private CompetitionRoundRepository $competitionRoundRepository,
    ) {
    }

    #[Route(
        path: '/{_locale}/move-round/{roundId}',
        name: 'move_competition_round',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);

        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $round->competition->id->toString());

        throw new NotFoundHttpException();
    }
}
