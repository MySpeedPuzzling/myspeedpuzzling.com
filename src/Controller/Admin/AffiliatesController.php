<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Query\GetAllAffiliates;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Value\AffiliateStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
final class AffiliatesController extends AbstractController
{
    public function __construct(
        readonly private GetAllAffiliates $getAllAffiliates,
    ) {
    }

    #[Route(path: '/admin/affiliates', name: 'admin_affiliates')]
    public function __invoke(Request $request): Response
    {
        $tab = $request->query->getString('tab', 'pending');
        $status = AffiliateStatus::tryFrom($tab) ?? AffiliateStatus::Pending;

        return $this->render('admin/affiliates.html.twig', [
            'affiliates' => $this->getAllAffiliates->byStatus($status),
            'counts' => $this->getAllAffiliates->countByStatus(),
            'active_tab' => $status->value,
        ]);
    }
}
