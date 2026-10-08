<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use SpeedPuzzling\Web\Query\GetOrganizations;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The admin approval queue: one-time events, organizations (docs/features/organizations/README.md "Admin approval
 * queue") and series waiting for approval - never drafts (they are submitted by publishing them).
 */
final class CompetitionApprovalsController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly GetCompetitionSeries $getCompetitionSeries,
        private readonly GetOrganizations $getOrganizations,
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
        $unapprovedSeries = $this->getCompetitionSeries->allUnapproved();

        return $this->render('admin/competition_approvals.html.twig', [
            'competitions' => $unapprovedCompetitions,
            'series' => $unapprovedSeries,
            'organizations' => $this->getOrganizations->allUnapproved(),
        ]);
    }
}
