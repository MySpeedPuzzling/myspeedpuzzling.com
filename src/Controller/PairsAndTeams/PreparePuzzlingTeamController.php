<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PairsAndTeams;

use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\Message\PreparePuzzlingTeam;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PreparePuzzlingTeamController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/pairs-and-teams/new',
        name: 'pairs_and_teams_prepare',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        if ($this->isCsrfTokenValid(PairsAndTeamsController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        /** @var array<string> $groupPlayers */
        $groupPlayers = array_values(array_filter(
            $request->request->all('group_players'),
            static fn(mixed $groupPlayer): bool => is_string($groupPlayer) && trim($groupPlayer) !== '',
        ));

        try {
            $this->messageBus->dispatch(new PreparePuzzlingTeam($player->playerId, $groupPlayers, $request->request->getString('team_name')));

            $this->addFlash('success', $this->translator->trans('pairs_and_teams.flash.prepared'));
        } catch (HandlerFailedException $exception) {
            if (!$exception->getPrevious() instanceof CanNotAssembleEmptyGroup) {
                throw $exception;
            }

            // Nobody picked, or only the player themselves
            $this->addFlash('danger', $this->translator->trans('pairs_and_teams.flash.nobody_picked'));
        }

        return $this->redirectToRoute('pairs_and_teams', ['show' => count($groupPlayers) > 1 ? 'teams' : 'pairs']);
    }
}
