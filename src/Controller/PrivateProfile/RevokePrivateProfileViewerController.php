<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PrivateProfile;

use SpeedPuzzling\Web\Message\RevokePrivateProfileViewer;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Removing somebody works without a membership too - taking visibility away must never be locked.
 */
final class RevokePrivateProfileViewerController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/en/who-can-see-my-profile/{playerId}/remove',
        name: 'private_profile_viewer_revoke',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $playerId): Response
    {
        if ($this->isCsrfTokenValid(PrivateProfileViewersController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $owner = $this->retrieveLoggedUserProfile->getProfile();
        assert($owner !== null);

        $this->messageBus->dispatch(new RevokePrivateProfileViewer($owner->playerId, $playerId));

        $this->addFlash('warning', $this->translator->trans('private_profile_viewers.removed'));

        return $this->redirectToRoute('private_profile_viewers');
    }
}
