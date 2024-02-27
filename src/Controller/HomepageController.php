<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetLastSolvedPuzzle;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomepageController extends AbstractController
{
    public function __construct(
        readonly private GetLastSolvedPuzzle $getLastSolvedPuzzle,
    ) {
    }

    #[Route(path: '/', name: 'homepage_crossroads', methods: ['GET'])]
    #[Route(
        path: [
            'cs' => '/uvod',
            'en' => '/en/home',
        ],
        name: 'homepage',
        methods: ['GET']
    )]
    public function __invoke(Request $request): Response
    {
        if ($request->getPathInfo() === '/') {
            return $this->redirectToRoute('homepage', ['_locale' => $request->getPreferredLanguage(['en', 'cs']) ?? 'en']);
        }

        return $this->render('homepage.html.twig', [
            'last_solved_puzzles' => $this->getLastSolvedPuzzle->limit(5),
        ]);
    }
}
