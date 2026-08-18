<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Query\GetAdminReferralDetail;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
final class ReferralDetailController extends AbstractController
{
    public function __construct(
        readonly private GetAdminReferralDetail $getAdminReferralDetail,
    ) {
    }

    #[Route(path: '/admin/referrals/{playerId}', name: 'admin_referral_detail')]
    public function __invoke(string $playerId): Response
    {
        $affiliate = $this->getAdminReferralDetail->affiliate($playerId);

        return $this->render('admin/referral_detail.html.twig', [
            'affiliate' => $affiliate,
            'totals' => $this->getAdminReferralDetail->payoutTotals($playerId),
            'referrals' => $this->getAdminReferralDetail->referrals($playerId),
            'payouts' => $this->getAdminReferralDetail->payouts($playerId),
        ]);
    }
}
