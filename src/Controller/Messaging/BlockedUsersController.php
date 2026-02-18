<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Messaging;

use SpeedPuzzling\Web\Query\GetUserBlocks;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class BlockedUsersController extends AbstractController
{
    public function __construct(
        readonly private GetUserBlocks $getUserBlocks,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: '/en/blocked-users',
        name: 'blocked_users',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $blockedUsers = $this->getUserBlocks->forPlayer($loggedPlayer->playerId);

        return $this->render('messaging/blocked_users.html.twig', [
            'blocked_users' => $blockedUsers,
        ]);
    }
}
