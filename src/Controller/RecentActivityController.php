<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetLastSolvedPuzzle;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RecentActivityController extends AbstractController
{
    public function __construct(
        readonly private GetLastSolvedPuzzle $getLastSolvedPuzzle,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/nedavna-aktivita',
            'en' => '/en/recent-activity',
        ],
        name: 'recent_activity',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): Response
    {
        return $this->render('recent_activity.html.twig', [
            'last_solved_puzzles' => $this->getLastSolvedPuzzle->limit(50),
        ]);
    }
}
