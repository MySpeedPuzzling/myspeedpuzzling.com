<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\CatalogueNumbersProvider;
use SpeedPuzzling\Web\Services\CatalogueStatsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PuzzlesController extends AbstractController
{
    public function __construct(
        readonly private CatalogueStatsProvider $catalogueStatsProvider,
        readonly private CatalogueNumbersProvider $catalogueNumbersProvider,
    ) {
    }

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
        return $this->render('puzzles.html.twig', [
            // Under the list: the database in numbers and plain links into the catalogue hubs (both cached)
            'catalogue' => $this->catalogueNumbersProvider->numbers(),
            'popular_brands' => $this->catalogueStatsProvider->mostPopularBrands(),
            'allowed_pieces' => PiecesPuzzlesController::ALLOWED_PIECES,
        ]);
    }
}
