<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Message\ApproveAffiliate;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
final class ApproveAffiliateController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(path: '/admin/affiliates/{affiliateId}/approve', name: 'admin_approve_affiliate', methods: ['POST'])]
    public function __invoke(string $affiliateId): Response
    {
        $this->messageBus->dispatch(new ApproveAffiliate($affiliateId));

        $this->addFlash('success', 'Affiliate approved.');

        return $this->redirectToRoute('admin_affiliate_detail', ['affiliateId' => $affiliateId]);
    }
}
