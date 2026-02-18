<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Query\GetReports;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ModerationDashboardController extends AbstractController
{
    public function __construct(
        private readonly GetReports $getReports,
    ) {
    }

    #[Route(
        path: '/admin/moderation',
        name: 'admin_moderation_dashboard',
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(Request $request): Response
    {
        $tab = $request->query->getString('tab', 'pending');

        $reports = match ($tab) {
            'all' => $this->getReports->all(),
            default => $this->getReports->pending(),
        };

        return $this->render('admin/moderation/dashboard.html.twig', [
            'reports' => $reports,
            'active_tab' => $tab,
            'counts' => $this->getReports->countByStatus(),
        ]);
    }
}
