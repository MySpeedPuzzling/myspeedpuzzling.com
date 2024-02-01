<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetLastSolvedPuzzle;
use SpeedPuzzling\Web\Query\GetPlatformChanges;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class HomepageController extends AbstractController
{
    public function __construct(
        readonly private GetLastSolvedPuzzle $getLastSolvedPuzzle,
        readonly private GetPlatformChanges $getPlatformChanges,
    ) {
    }

    #[Route(path: '/', methods: ['GET'])]
    #[Route(path: '/{_locale<cs|en>}', name: 'homepage', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        return $this->render('homepage.html.twig', [
            'last_solved_puzzles' => $this->getLastSolvedPuzzle->limit(5),
            'recent_changes' => $this->getPlatformChanges->recentDays(3),
        ]);
    }
}
