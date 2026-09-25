<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Query\GetPuzzleApprovals;
use SpeedPuzzling\Web\Security\PuzzleModerationVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class PuzzleApprovalsController extends AbstractController
{
    public function __construct(
        private readonly GetPuzzleApprovals $getPuzzleApprovals,
    ) {
    }

    #[Route(
        path: '/admin/puzzle-approvals',
        name: 'admin_puzzle_approvals',
    )]
    #[IsGranted(PuzzleModerationVoter::PUZZLE_MODERATION_ACCESS)]
    public function __invoke(Request $request): Response
    {
        $tab = $request->query->getString('tab', 'pending') === 'approved' ? 'approved' : 'pending';
        $page = max(1, $request->query->getInt('page', 1));

        $counts = [
            'pending' => $this->getPuzzleApprovals->countPending(),
            'approved' => $this->getPuzzleApprovals->countApproved(),
        ];

        return $this->render('admin/puzzle_approvals.html.twig', [
            'active_tab' => $tab,
            'page' => $page,
            'pages' => max(1, (int) ceil($counts[$tab] / GetPuzzleApprovals::PAGE_SIZE)),
            'counts' => $counts,
            'pending' => $tab === 'pending' ? $this->getPuzzleApprovals->pending($page) : [],
            'approved' => $tab === 'approved' ? $this->getPuzzleApprovals->recentlyApproved($page) : [],
        ]);
    }
}
