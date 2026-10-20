<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\DeclineModeratorInvitation;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Not now" at the end of the moderator guide - always the player's own open invitation
 */
final class DeclineModeratorInvitationController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'decline-moderator-invitation';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    #[Route(path: '/en/moderator-guide/decline', name: 'moderator_invitation_decline', defaults: ['_locale' => 'en'], methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null || $this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $this->messageBus->dispatch(new DeclineModeratorInvitation($profile->playerId));

        $this->addFlash('info', 'Thank you for considering it. If you change your mind, just let us know.');

        return $this->redirectToRoute('hub');
    }
}
