<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Message\MarkPayoutPaid;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
final class MarkPayoutPaidController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(path: '/admin/payouts/{payoutId}/mark-paid', name: 'admin_mark_payout_paid', methods: ['POST'])]
    public function __invoke(string $payoutId, string $affiliateId): Response
    {
        $this->messageBus->dispatch(new MarkPayoutPaid($payoutId));

        $this->addFlash('success', 'Payout marked as paid.');

        return $this->redirectToRoute('admin_affiliate_detail', ['affiliateId' => $affiliateId]);
    }
}
