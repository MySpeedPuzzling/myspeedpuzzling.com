<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PairsAndTeams;

use SpeedPuzzling\Web\Message\RenameTeamGuest;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RenameTeamGuestController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/pairs-and-teams/guests/rename',
        name: 'pairs_and_teams_rename_guest',
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

        $newName = trim($request->request->getString('name'));

        if ($newName === '' || str_starts_with($newName, '#')) {
            // A name starting with # would read as a player code the next time it is typed into the form
            $this->addFlash('danger', $this->translator->trans('pairs_and_teams.guests.flash.invalid_name'));
        } else {
            $this->messageBus->dispatch(new RenameTeamGuest($player->playerId, $request->request->getString('guest'), $newName));
            $this->addFlash('success', $this->translator->trans('pairs_and_teams.guests.flash.renamed'));
        }

        return $this->redirectToRoute('pairs_and_teams', ['show' => 'guests']);
    }
}
