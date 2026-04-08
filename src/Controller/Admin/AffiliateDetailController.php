<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Query\GetAffiliateDetail;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
final class AffiliateDetailController extends AbstractController
{
    public function __construct(
        readonly private GetAffiliateDetail $getAffiliateDetail,
    ) {
    }

    #[Route(path: '/admin/affiliates/{affiliateId}', name: 'admin_affiliate_detail')]
    public function __invoke(string $affiliateId): Response
    {
        $affiliate = $this->getAffiliateDetail->overview($affiliateId);

        if ($affiliate === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/affiliate_detail.html.twig', [
            'affiliate' => $affiliate,
            'tributes' => $this->getAffiliateDetail->tributes($affiliateId),
            'payouts' => $this->getAffiliateDetail->payouts($affiliateId),
        ]);
    }
}
