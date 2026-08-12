<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Message\UnsuspendFromReferralProgram;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
final class UnsuspendAffiliateController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(path: '/admin/referrals/{playerId}/unsuspend', name: 'admin_unsuspend_affiliate', methods: ['POST'])]
    public function __invoke(string $playerId): Response
    {
        $this->messageBus->dispatch(new UnsuspendFromReferralProgram($playerId));

        $this->addFlash('success', 'Player unsuspended from referral program.');

        return $this->redirectToRoute('admin_referral_detail', ['playerId' => $playerId]);
    }
}
