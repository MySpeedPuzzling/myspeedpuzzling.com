<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PairsAndTeams;

use SpeedPuzzling\Web\Message\AnswerGuestLink;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AnswerGuestLinkController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/pairs-and-teams/guest-link/{requestId}/answer',
        name: 'pairs_and_teams_guest_link_answer',
        requirements: ['requestId' => Requirement::UUID],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $requestId): Response
    {
        if ($this->isCsrfTokenValid(PairsAndTeamsController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $accept = $request->request->getString('answer') === 'accept';

        $this->messageBus->dispatch(new AnswerGuestLink($requestId, $player->playerId, $accept));

        $this->addFlash($accept ? 'success' : 'warning', $this->translator->trans($accept ? 'pairs_and_teams.guest_link.flash.accepted' : 'pairs_and_teams.guest_link.flash.declined'));

        return $this->redirectToRoute($accept ? 'pairs_and_teams' : 'notifications');
    }
}
