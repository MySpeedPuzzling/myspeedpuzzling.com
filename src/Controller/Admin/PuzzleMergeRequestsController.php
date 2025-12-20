<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Query\GetPuzzleMergeRequests;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class PuzzleMergeRequestsController extends AbstractController
{
    public function __construct(
        private readonly GetPuzzleMergeRequests $getPuzzleMergeRequests,
    ) {
    }

    #[Route(
        path: '/admin/puzzle-merge-requests',
        name: 'admin_puzzle_merge_requests',
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(
        #[CurrentUser] User $user,
        Request $request,
    ): Response {
        $tab = $request->query->getString('tab', 'pending');

        $requests = match ($tab) {
            'approved' => $this->getPuzzleMergeRequests->allApproved(),
            'rejected' => $this->getPuzzleMergeRequests->allRejected(),
            default => $this->getPuzzleMergeRequests->allPending(),
        };

        return $this->render('admin/puzzle_merge_requests.html.twig', [
            'requests' => $requests,
            'active_tab' => $tab,
            'pending_count' => count($this->getPuzzleMergeRequests->allPending()),
        ]);
    }
}
