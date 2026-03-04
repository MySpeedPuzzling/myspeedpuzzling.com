<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class CompetitionApprovalsController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
    ) {
    }

    #[Route(
        path: '/admin/competition-approvals',
        name: 'admin_competition_approvals',
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(): Response
    {
        $unapprovedCompetitions = $this->getCompetitionEvents->allUnapproved();

        return $this->render('admin/competition_approvals.html.twig', [
            'competitions' => $unapprovedCompetitions,
        ]);
    }
}
