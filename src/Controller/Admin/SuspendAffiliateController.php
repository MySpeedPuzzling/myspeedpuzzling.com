<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Message\SuspendAffiliate;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
final class SuspendAffiliateController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(path: '/admin/affiliates/{affiliateId}/suspend', name: 'admin_suspend_affiliate', methods: ['POST'])]
    public function __invoke(string $affiliateId): Response
    {
        $this->messageBus->dispatch(new SuspendAffiliate($affiliateId));

        $this->addFlash('warning', 'Affiliate suspended.');

        return $this->redirectToRoute('admin_affiliate_detail', ['affiliateId' => $affiliateId]);
    }
}
