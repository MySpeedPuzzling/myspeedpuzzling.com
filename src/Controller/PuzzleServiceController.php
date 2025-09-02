<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PuzzleServiceController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/pujcit-puzzle',
            'en' => '/en/borrow-puzzle',
            'es' => '/es/prestar-puzzle',
            'ja' => '/ja/パズル貿与',
            'fr' => '/fr/emprunter-puzzle',
        ],
        name: 'puzzle_service',
    )]
    public function __invoke(Request $request): Response
    {
        return $this->render('puzzle-service.html.twig');
    }
}
