<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use Auth0\Symfony\Models\User;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetAllVouchers;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class VouchersController extends AbstractController
{
    public function __construct(
        private readonly GetAllVouchers $getAllVouchers,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        path: '/admin/vouchers',
        name: 'admin_vouchers',
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(
        #[CurrentUser] User $user,
        Request $request,
    ): Response {
        $tab = $request->query->getString('tab', 'available');
        $now = $this->clock->now();

        $vouchers = match ($tab) {
            'used' => $this->getAllVouchers->allUsed(),
            'expired' => $this->getAllVouchers->allExpired($now),
            default => $this->getAllVouchers->allAvailable($now),
        };

        return $this->render('admin/vouchers.html.twig', [
            'vouchers' => $vouchers,
            'active_tab' => $tab,
            'counts' => $this->getAllVouchers->countByStatus($now),
            'now' => $now,
        ]);
    }
}
