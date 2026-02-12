<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Message\BanFromMarketplace;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class BanFromMarketplaceController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: '/admin/moderation/ban/{playerId}',
        name: 'admin_ban_marketplace',
        methods: ['POST'],
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(Request $request, string $playerId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            throw $this->createAccessDeniedException();
        }

        $reason = $request->request->getString('reason');
        $reportId = $request->request->getString('report_id');

        $this->messageBus->dispatch(new BanFromMarketplace(
            targetPlayerId: $playerId,
            adminId: $player->playerId,
            reason: $reason,
            reportId: $reportId !== '' ? $reportId : null,
        ));

        $this->addFlash('success', 'User has been banned from the marketplace.');

        return $this->redirectToRoute('admin_moderation_dashboard');
    }
}
