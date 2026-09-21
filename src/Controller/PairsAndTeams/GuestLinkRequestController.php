<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PairsAndTeams;

use SpeedPuzzling\Web\Query\GetGuestLinkRequests;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * What the asked player sees: who says they are which guest, and the results saying yes would add to
 * their history. Viewing changes nothing - answering is a POST (AnswerGuestLinkController).
 */
final class GuestLinkRequestController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetGuestLinkRequests $getGuestLinkRequests,
    ) {
    }

    #[Route(
        path: '/{_locale}/pairs-and-teams/guest-link/{requestId}',
        name: 'pairs_and_teams_guest_link',
        requirements: ['requestId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(string $requestId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        return $this->render('pairs_and_teams/guest_link.html.twig', [
            'link_request' => $this->getGuestLinkRequests->forTarget($requestId, $player->playerId),
        ]);
    }
}
