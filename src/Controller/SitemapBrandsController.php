<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetBrandDirectory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Catalogue landing pages: the brand directory, brand hubs, piece-count hubs
 * and brand × pieces pages. Only indexable pages are listed (the same rules as
 * the robots meta on the pages themselves); numbered pages 2+ are left out on
 * purpose - crawlers reach them through the pagination links.
 */
final class SitemapBrandsController extends AbstractController
{
    use SitemapResponseTrait;

    public function __construct(
        readonly private GetBrandDirectory $getBrandDirectory,
    ) {
    }

    #[Route(path: '/sitemap-brands.xml', name: 'sitemap_brands')]
    public function __invoke(): Response
    {
        $entries = $this->localizedEntries('puzzle_brands');

        foreach ($this->getBrandDirectory->indexableBrands() as $brand) {
            array_push($entries, ...$this->localizedEntries('brand_puzzles', [
                'slug' => $brand->slug,
            ]));
        }

        foreach (PiecesPuzzlesController::ALLOWED_PIECES as $pieces) {
            array_push($entries, ...$this->localizedEntries('pieces_puzzles', [
                'pieces' => $pieces,
            ]));
        }

        foreach ($this->getBrandDirectory->indexableBrandPiecesPages() as $brandPiecesPage) {
            array_push($entries, ...$this->localizedEntries('brand_pieces_puzzles', [
                'slug' => $brandPiecesPage['slug'],
                'pieces' => $brandPiecesPage['pieces'],
            ]));
        }

        return $this->urlsetResponse($entries);
    }
}
