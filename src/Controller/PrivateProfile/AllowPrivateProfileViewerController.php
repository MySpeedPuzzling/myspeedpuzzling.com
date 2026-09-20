<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PrivateProfile;

use SpeedPuzzling\Web\Exceptions\PrivateProfileViewersLimitReached;
use SpeedPuzzling\Web\Message\AllowPrivateProfileViewer;
use SpeedPuzzling\Web\MessageHandler\AllowPrivateProfileViewerHandler;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One click on a suggested player (somebody the owner follows).
 */
final class AllowPrivateProfileViewerController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/en/who-can-see-my-profile/{playerId}/allow',
        name: 'private_profile_viewer_allow',
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

        if ($owner->activeMembership === false) {
            return $this->redirectToRoute('edit_profile');
        }

        try {
            $this->messageBus->dispatch(new AllowPrivateProfileViewer($owner->playerId, $playerId));
            $this->addFlash('success', $this->translator->trans('private_profile_viewers.added'));
        } catch (HandlerFailedException $exception) {
            if (!$exception->getPrevious() instanceof PrivateProfileViewersLimitReached) {
                throw $exception;
            }

            $this->addFlash('warning', $this->translator->trans('private_profile_viewers.limit_reached', [
                '%limit%' => AllowPrivateProfileViewerHandler::MAX_VIEWERS,
            ]));
        }

        return $this->redirectToRoute('private_profile_viewers');
    }
}
