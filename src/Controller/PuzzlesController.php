<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PuzzlesController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/puzzle',
            'en' => '/en/puzzle',
            'es' => '/es/puzzles',
            'ja' => '/ja/パズル',
            'fr' => '/fr/puzzle',
            'de' => '/de/puzzle',
        ],
        name: 'puzzles',
    )]
    public function __invoke(): Response
    {
        return $this->render('puzzles.html.twig');
    }
}
