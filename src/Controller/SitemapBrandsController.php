<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetBrandSlugsForSitemap;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Brand hub + piece-count hub landing pages. Only indexable brand hubs are
 * listed (same thin-page gating rule as the noindex meta on the page itself).
 */
final class SitemapBrandsController extends AbstractController
{
    use SitemapResponseTrait;

    public function __construct(
        readonly private GetBrandSlugsForSitemap $getBrandSlugsForSitemap,
    ) {
    }

    #[Route(path: '/sitemap-brands.xml', name: 'sitemap_brands')]
    public function __invoke(): Response
    {
        $entries = [];

        foreach ($this->getBrandSlugsForSitemap->indexable() as $slug) {
            array_push($entries, ...$this->localizedEntries('brand_puzzles', [
                'slug' => $slug,
            ]));
        }

        foreach (PiecesPuzzlesController::ALLOWED_PIECES as $pieces) {
            array_push($entries, ...$this->localizedEntries('pieces_puzzles', [
                'pieces' => $pieces,
            ]));
        }

        return $this->urlsetResponse($entries);
    }
}
