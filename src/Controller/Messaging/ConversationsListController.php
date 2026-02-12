<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Messaging;

use SpeedPuzzling\Web\Query\GetConversations;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ConversationsListController extends AbstractController
{
    public function __construct(
        readonly private GetConversations $getConversations,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: '/en/messages',
        name: 'conversations_list',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $tab = $request->query->getString('tab', 'conversations');

        $conversations = $this->getConversations->forPlayer($loggedPlayer->playerId);
        $pendingRequests = $this->getConversations->pendingRequestsForPlayer($loggedPlayer->playerId);

        return $this->render('messaging/conversations.html.twig', [
            'conversations' => $conversations,
            'pending_requests' => $pendingRequests,
            'pending_count' => count($pendingRequests),
            'active_tab' => $tab,
        ]);
    }
}
