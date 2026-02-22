<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetStatistics;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PuzzleTrackerAppController extends AbstractController
{
    public function __construct(
        readonly private GetStatistics $getStatistics,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/sledovani-puzzli-aplikace',
            'en' => '/en/puzzle-tracker-app',
            'es' => '/es/aplicacion-seguimiento-puzzles',
            'ja' => '/ja/パズルトラッカーアプリ',
            'fr' => '/fr/application-suivi-puzzles',
            'de' => '/de/puzzle-tracker-app',
        ],
        name: 'puzzle_tracker_app',
    )]
    public function __invoke(): Response
    {
        return $this->render('puzzle-tracker-app.html.twig', [
            'global_statistics' => $this->getStatistics->globally(),
        ]);
    }
}
