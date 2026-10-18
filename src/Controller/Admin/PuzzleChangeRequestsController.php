<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use Symfony\Component\Security\Core\User\UserInterface;
use SpeedPuzzling\Web\Query\GetProposerTrackRecords;
use SpeedPuzzling\Web\Query\GetPuzzleChangeRequests;
use SpeedPuzzling\Web\Security\PuzzleModerationVoter;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Value\PuzzleChangeRequestFilter;
use SpeedPuzzling\Web\Value\PuzzleChangeRequestSource;
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
        private readonly GetProposerTrackRecords $getProposerTrackRecords,
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
        // Who filed it / one of its tags - kept across the tabs (?source=image_pipeline&tag=artwork_crop)
        $filter = PuzzleChangeRequestFilter::fromRequest($request);

        $requests = match ($tab) {
            'approved' => $this->getPuzzleChangeRequests->allApproved($isAdmin, $filter),
            'rejected' => $this->getPuzzleChangeRequests->allRejected($isAdmin, $filter),
            'outdated' => $this->getPuzzleChangeRequests->allOutdated($isAdmin, $filter),
            default => $this->getPuzzleChangeRequests->allPending($isAdmin, $filter),
        };

        return $this->render('admin/puzzle_change_requests.html.twig', [
            'requests' => $requests,
            // Karma (docs/features/karma.md): one statement for the whole page
            'track_records' => $this->getProposerTrackRecords->forPlayers(array_map(static fn ($request): string => $request->reporterId, $requests)),
            'active_tab' => $tab,
            'counts' => $this->getPuzzleChangeRequests->countByStatus($isAdmin, $filter),
            'filter' => $filter,
            'sources' => PuzzleChangeRequestSource::cases(),
            'known_tags' => $this->getPuzzleChangeRequests->knownTags(),
            // The picture review grid (PuzzleChangeRequestPicturesController) has work
            'pending_pictures' => $this->getPuzzleChangeRequests->countPendingPictureProposals($filter, $isAdmin),
        ]);
    }
}
