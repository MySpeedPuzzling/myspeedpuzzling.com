<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\DeleteCompetitionStanding;
use SpeedPuzzling\Web\Query\GetCompetitionStandings;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class DeleteCompetitionStandingController extends AbstractController
{
    public const string CSRF_TOKEN_PREFIX = 'delete-standing-';

    public function __construct(
        private readonly GetCompetitionStandings $getCompetitionStandings,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/poradi-udalosti/{competitionId}/{standingId}/smazat',
            'en' => '/en/manage-event-standings/{competitionId}/{standingId}/delete',
            'es' => '/es/manage-event-standings/{competitionId}/{standingId}/delete',
            'ja' => '/ja/manage-event-standings/{competitionId}/{standingId}/delete',
            'fr' => '/fr/manage-event-standings/{competitionId}/{standingId}/delete',
            'de' => '/de/manage-event-standings/{competitionId}/{standingId}/delete',
        ],
        name: 'delete_competition_standing',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT, 'standingId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $competitionId, string $standingId): Response
    {
        $standing = $this->getCompetitionStandings->byId($standingId);

        if ($standing->competitionId !== strtolower($competitionId)) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $standing->competitionId);

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_PREFIX . $standing->id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->messageBus->dispatch(new DeleteCompetitionStanding($standing->id, $standing->competitionId));

        $this->addFlash('success', $this->translator->trans('competition_standing.flash.deleted', ['%name%' => $standing->name]));

        return $this->redirectToRoute('competition_standings_manage', ['competitionId' => $standing->competitionId], Response::HTTP_SEE_OTHER);
    }
}
