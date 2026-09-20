<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PairsAndTeams;

use SpeedPuzzling\Web\Message\RenamePuzzlingTeam;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RenamePuzzlingTeamController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/pairs-and-teams/{teamId}/rename',
        name: 'pairs_and_teams_rename',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $teamId): Response
    {
        if ($this->isCsrfTokenValid(PairsAndTeamsController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $this->messageBus->dispatch(new RenamePuzzlingTeam($teamId, $player->playerId, $request->request->getString('name')));

        $this->addFlash('success', $this->translator->trans('pairs_and_teams.flash.renamed'));

        return $this->redirectToRoute('pairs_and_teams', ['show' => $request->request->getString('show') === 'teams' ? 'teams' : 'pairs']);
    }
}
