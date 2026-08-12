<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Query\GetAdminReferralsOverview;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
final class ReferralsOverviewController extends AbstractController
{
    public function __construct(
        readonly private GetAdminReferralsOverview $getAdminReferralsOverview,
    ) {
    }

    #[Route(path: '/admin/referrals', name: 'admin_referrals')]
    public function __invoke(): Response
    {
        return $this->render('admin/referrals.html.twig', [
            'totals' => $this->getAdminReferralsOverview->totalsPerCurrency(),
            'affiliates' => $this->getAdminReferralsOverview->affiliates(),
        ]);
    }
}
