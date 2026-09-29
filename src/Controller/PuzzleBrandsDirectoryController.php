<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Results\BrandDirectoryEntry;
use SpeedPuzzling\Web\Services\CatalogueStatsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A–Z directory of every brand whose hub is indexable - a crawl path to all
 * brand hubs and the answer to "which puzzle brands are there".
 */
final class PuzzleBrandsDirectoryController extends AbstractController
{
    public function __construct(
        readonly private CatalogueStatsProvider $catalogueStatsProvider,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/puzzle/znacky',
            'en' => '/en/puzzle/brands',
            'es' => '/es/puzzles/marcas',
            'ja' => '/ja/パズル/ブランド',
            'fr' => '/fr/puzzle/marques',
            'de' => '/de/puzzle/marken',
        ],
        name: 'puzzle_brands',
        // Must win over puzzle_detail's catch-all {puzzleId} segment.
        priority: 10,
    )]
    public function __invoke(): Response
    {
        $brands = $this->catalogueStatsProvider->brandDirectory();

        $brandsByLetter = [];

        foreach ($brands as $brand) {
            $brandsByLetter[$brand->letter][] = $brand;
        }

        // A–Z first, brands starting with anything else at the end
        uksort($brandsByLetter, static fn (string $a, string $b): int => [$a === BrandDirectoryEntry::OTHER_LETTER, $a] <=> [$b === BrandDirectoryEntry::OTHER_LETTER, $b]);

        return $this->render('puzzle/brands_directory.html.twig', [
            'brands_count' => count($brands),
            'puzzles_count' => array_sum(array_map(static fn (BrandDirectoryEntry $brand): int => $brand->puzzlesCount, $brands)),
            'brands_by_letter' => $brandsByLetter,
            'most_popular' => $this->catalogueStatsProvider->mostPopularBrands(),
        ]);
    }
}
