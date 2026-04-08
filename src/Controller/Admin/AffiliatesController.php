<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Query\GetReferralProgramMembers;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
final class AffiliatesController extends AbstractController
{
    public function __construct(
        readonly private GetReferralProgramMembers $getReferralProgramMembers,
        readonly private PlayerRepository $playerRepository,
    ) {
    }

    #[Route(path: '/admin/affiliates', name: 'admin_affiliates')]
    public function __invoke(Request $request): Response
    {
        $tab = $request->query->getString('tab', 'active');
        $suspended = $tab === 'suspended';

        return $this->render('admin/affiliates.html.twig', [
            'members' => $this->getReferralProgramMembers->byStatus($suspended),
            'counts' => $this->getReferralProgramMembers->countByStatus(),
            'active_tab' => $tab,
        ]);
    }

    #[Route(path: '/admin/affiliates/{playerId}/suspend', name: 'admin_suspend_affiliate', methods: ['POST'])]
    public function suspend(string $playerId): Response
    {
        $player = $this->playerRepository->get($playerId);
        $player->suspendFromReferralProgram();

        $this->addFlash('warning', 'Player suspended from referral program.');

        return $this->redirectToRoute('admin_affiliates');
    }

    #[Route(path: '/admin/affiliates/{playerId}/unsuspend', name: 'admin_unsuspend_affiliate', methods: ['POST'])]
    public function unsuspend(string $playerId): Response
    {
        $player = $this->playerRepository->get($playerId);
        $player->unsuspendFromReferralProgram();

        $this->addFlash('success', 'Player unsuspended from referral program.');

        return $this->redirectToRoute('admin_affiliates');
    }
}
