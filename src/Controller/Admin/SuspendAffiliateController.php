<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Message\SuspendFromReferralProgram;
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

    #[Route(path: '/admin/referrals/{playerId}/suspend', name: 'admin_suspend_affiliate', methods: ['POST'])]
    public function __invoke(string $playerId): Response
    {
        $this->messageBus->dispatch(new SuspendFromReferralProgram($playerId));

        $this->addFlash('warning', 'Player suspended from referral program.');

        return $this->redirectToRoute('admin_referral_detail', ['playerId' => $playerId]);
    }
}
