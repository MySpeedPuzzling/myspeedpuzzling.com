<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Message\RevokeModeratorRole;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
final class RevokeModeratorController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'revoke-moderator';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/admin/moderators/{playerId}/revoke', name: 'admin_revoke_moderator', methods: ['POST'])]
    public function __invoke(Request $request, string $playerId): Response
    {
        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $this->messageBus->dispatch(new RevokeModeratorRole($playerId));

        $this->addFlash('warning', $this->translator->trans('admin.moderators.revoked'));

        return $this->redirectToRoute('admin_moderators');
    }
}
