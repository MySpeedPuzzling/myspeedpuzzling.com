<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PairsAndTeams;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\GuestLinkNotPossible;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\RequestGuestLink;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "This guest has an account now": asks the named player whether they are that guest.
 */
final class RequestGuestLinkController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/pairs-and-teams/guests/link',
        name: 'pairs_and_teams_link_guest',
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

        try {
            $this->messageBus->dispatch(new RequestGuestLink(
                Uuid::uuid7(),
                $player->playerId,
                $request->request->getString('guest'),
                $request->request->getString('code'),
            ));

            $this->addFlash('success', $this->translator->trans('pairs_and_teams.guests.flash.link_sent'));
        } catch (PlayerNotFound) {
            $this->addFlash('danger', $this->translator->trans('pairs_and_teams.guests.flash.link_unknown_code'));
        } catch (HandlerFailedException $exception) {
            if (!$exception->getPrevious() instanceof GuestLinkNotPossible) {
                throw $exception;
            }

            $this->addFlash('danger', $this->translator->trans('pairs_and_teams.guests.flash.link_not_possible'));
        }

        return $this->redirectToRoute('pairs_and_teams', ['show' => 'guests']);
    }
}
