<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Message\WithdrawModeratorInvitation;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
final class WithdrawModeratorInvitationController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'withdraw-moderator-invitation';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/admin/moderator-invitations/{invitationId}/withdraw', name: 'admin_withdraw_moderator_invitation', methods: ['POST'])]
    public function __invoke(Request $request, string $invitationId): Response
    {
        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $this->messageBus->dispatch(new WithdrawModeratorInvitation($invitationId));

        $this->addFlash('warning', $this->translator->trans('admin.moderators.invitation_withdrawn'));

        return $this->redirectToRoute('admin_moderators');
    }
}
