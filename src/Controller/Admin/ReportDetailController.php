<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Query\GetModerationActions;
use SpeedPuzzling\Web\Query\GetReports;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ReportDetailController extends AbstractController
{
    public function __construct(
        private readonly GetReports $getReports,
        private readonly GetModerationActions $getModerationActions,
    ) {
    }

    #[Route(
        path: '/admin/moderation/report/{reportId}',
        name: 'admin_report_detail',
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(string $reportId): Response
    {
        $report = $this->getReports->byId($reportId);
        $moderationHistory = $this->getModerationActions->forPlayer($report->reportedPlayerId);

        return $this->render('admin/moderation/report_detail.html.twig', [
            'report' => $report,
            'moderation_history' => $moderationHistory,
        ]);
    }
}
