<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Message\MuteUser;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MuteUserController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/admin/moderation/mute/{playerId}',
        name: 'admin_mute_user',
        methods: ['POST'],
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(Request $request, string $playerId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            throw $this->createAccessDeniedException();
        }

        $days = $request->request->getInt('days', 7);
        $reason = $request->request->getString('reason');
        $reportId = $request->request->getString('report_id');

        $this->messageBus->dispatch(new MuteUser(
            targetPlayerId: $playerId,
            adminId: $player->playerId,
            days: $days,
            reason: $reason,
            reportId: $reportId !== '' ? $reportId : null,
        ));

        $this->addFlash('success', $this->translator->trans('moderation.user_muted', ['%days%' => $days]));

        return $this->redirectToRoute('admin_moderation_dashboard');
    }
}
