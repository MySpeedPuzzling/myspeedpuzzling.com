<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use Symfony\Component\Security\Core\User\UserInterface;
use SpeedPuzzling\Web\Query\GetPuzzleChangeRequests;
use SpeedPuzzling\Web\Security\PuzzleModerationVoter;
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
    #[IsGranted(PuzzleModerationVoter::PUZZLE_MODERATION_ACCESS)]
    public function __invoke(
        #[CurrentUser] UserInterface $user,
        Request $request,
    ): Response {
        $tab = $request->query->getString('tab', 'pending');
        // Admins also see requests for secret competition puzzles - moderators never do (PuzzleSecrecy)
        $isAdmin = $this->isGranted(AdminAccessVoter::ADMIN_ACCESS);

        $requests = match ($tab) {
            'approved' => $this->getPuzzleChangeRequests->allApproved($isAdmin),
            'rejected' => $this->getPuzzleChangeRequests->allRejected($isAdmin),
            default => $this->getPuzzleChangeRequests->allPending($isAdmin),
        };

        return $this->render('admin/puzzle_change_requests.html.twig', [
            'requests' => $requests,
            'active_tab' => $tab,
            'counts' => $this->getPuzzleChangeRequests->countByStatus($isAdmin),
        ]);
    }
}
