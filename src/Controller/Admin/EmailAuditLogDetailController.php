<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Query\GetEmailAuditLogs;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class EmailAuditLogDetailController extends AbstractController
{
    public function __construct(
        private readonly GetEmailAuditLogs $getEmailAuditLogs,
    ) {
    }

    #[Route(
        path: '/admin/email-audit/{auditLogId}',
        name: 'admin_email_audit_detail',
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(string $auditLogId): Response
    {
        $detail = $this->getEmailAuditLogs->byId($auditLogId);

        return $this->render('admin/email_audit/detail.html.twig', [
            'log' => $detail,
        ]);
    }
}
