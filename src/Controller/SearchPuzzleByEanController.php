<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetManufacturers;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class SearchPuzzleByEanController extends AbstractController
{
    public function __construct(
        readonly private SearchPuzzle $searchPuzzle,
        readonly private GetManufacturers $getManufacturers,
    ) {
    }

    #[Route(
        path: '/{_locale}/puzzle-by-ean-search/{ean}',
        name: 'puzzle_by_ean_search',
    )]
    public function __invoke(string $ean): JsonResponse
    {
        $puzzleResults = $this->searchPuzzle->allByEan($ean);

        if ($puzzleResults === []) {
            // No puzzles found - try to find manufacturers by EAN prefix
            $manufacturerResults = $this->getManufacturers->allByEanPrefix($ean);

            $brands = [];
            foreach ($manufacturerResults as $manufacturer) {
                $brands[] = [
                    'id' => $manufacturer['manufacturer_id'],
                    'name' => $manufacturer['manufacturer_name'],
                    'eanPrefix' => $manufacturer['manufacturer_ean_prefix'],
                ];
            }

            return new JsonResponse([
                'puzzles' => [],
                'brands' => $brands,
                'ean' => $ean,
            ]);
        }

        // Puzzles found - return them with their unique brands
        $puzzles = [];
        $brands = [];
        $seenBrandIds = [];

        foreach ($puzzleResults as $result) {
            $puzzles[] = [
                'id' => $result['puzzle_id'],
                'name' => $result['puzzle_name'],
                'piecesCount' => $result['pieces_count'],
                'image' => $result['puzzle_image'],
                'ean' => $result['puzzle_ean'],
            ];

            // Add unique brands
            if (!isset($seenBrandIds[$result['manufacturer_id']])) {
                $seenBrandIds[$result['manufacturer_id']] = true;
                $brands[] = [
                    'id' => $result['manufacturer_id'],
                    'name' => $result['manufacturer_name'],
                ];
            }
        }

        return new JsonResponse([
            'puzzles' => $puzzles,
            'brands' => $brands,
            'ean' => $ean,
        ]);
    }
}
