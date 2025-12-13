<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\SearchPuzzle;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class SearchPuzzleByEanController extends AbstractController
{
    public function __construct(
        readonly private SearchPuzzle $searchPuzzle,
    ) {
    }

    #[Route(
        path: '/{_locale}/puzzle-by-ean-search/{ean}',
        name: 'puzzle_by_ean_search',
    )]
    public function __invoke(string $ean): JsonResponse
    {
        $result = $this->searchPuzzle->byEan($ean);

        if ($result === null) {
            return new JsonResponse([
                'found' => false,
                'ean' => $ean,
            ]);
        }

        return new JsonResponse([
            'found' => true,
            'puzzle' => [
                'id' => $result['puzzle_id'],
                'name' => $result['puzzle_name'],
                'piecesCount' => $result['pieces_count'],
                'image' => $result['puzzle_image'],
            ],
            'brand' => [
                'id' => $result['manufacturer_id'],
                'name' => $result['manufacturer_name'],
            ],
        ]);
    }
}
