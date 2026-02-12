<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Message\ResolveReport;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ReportStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ResolveReportController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/admin/moderation/report/{reportId}/resolve',
        name: 'admin_resolve_report',
        methods: ['POST'],
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(Request $request, string $reportId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            throw $this->createAccessDeniedException();
        }

        $statusValue = $request->request->getString('status', 'resolved');
        $status = ReportStatus::from($statusValue);
        $adminNote = $request->request->getString('admin_note');

        $this->messageBus->dispatch(new ResolveReport(
            reportId: $reportId,
            adminId: $player->playerId,
            status: $status,
            adminNote: $adminNote !== '' ? $adminNote : null,
        ));

        $this->addFlash('success', $this->translator->trans('moderation.report_resolved'));

        return $this->redirectToRoute('admin_moderation_dashboard');
    }
}
