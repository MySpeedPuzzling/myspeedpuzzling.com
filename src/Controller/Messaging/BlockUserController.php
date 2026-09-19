<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Messaging;

use SpeedPuzzling\Web\Message\BlockUser;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class BlockUserController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'block_player';

    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/en/block-user/{playerId}',
        name: 'block_user',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $playerId): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw new AccessDeniedHttpException();
        }

        $this->messageBus->dispatch(new BlockUser(
            blockerId: $loggedPlayer->playerId,
            blockedId: $playerId,
        ));

        $this->addFlash('success', $this->translator->trans('blocklist.blocked'));

        $referer = $request->headers->get('referer');

        // Blocked from their profile: that page is a 404 for the blocker from now on
        if ($referer !== null && str_contains($referer, $playerId)) {
            return $this->redirectToRoute('my_profile');
        }

        if ($referer !== null && $referer !== '') {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('conversations_list');
    }
}
