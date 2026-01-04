<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Query\GetPuzzleChangeRequests;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class PuzzleChangeRequestsController extends AbstractController
{
    public function __construct(
        private readonly GetPuzzleChangeRequests $getPuzzleChangeRequests,
    ) {
    }

    #[Route(
        path: '/admin/puzzle-change-requests',
        name: 'admin_puzzle_change_requests',
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(
        #[CurrentUser] User $user,
        Request $request,
    ): Response {
        $tab = $request->query->getString('tab', 'pending');

        $requests = match ($tab) {
            'approved' => $this->getPuzzleChangeRequests->allApproved(),
            'rejected' => $this->getPuzzleChangeRequests->allRejected(),
            default => $this->getPuzzleChangeRequests->allPending(),
        };

        return $this->render('admin/puzzle_change_requests.html.twig', [
            'requests' => $requests,
            'active_tab' => $tab,
            'counts' => $this->getPuzzleChangeRequests->countByStatus(),
        ]);
    }
}
