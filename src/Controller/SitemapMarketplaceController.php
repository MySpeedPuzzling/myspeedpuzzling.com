<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPuzzleIdsForSitemap;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SitemapMarketplaceController extends AbstractController
{
    use SitemapResponseTrait;

    public function __construct(
        readonly private GetPuzzleIdsForSitemap $getPuzzleIdsForSitemap,
    ) {
    }

    #[Route(path: '/sitemap-marketplace.xml', name: 'sitemap_marketplace')]
    public function __invoke(): Response
    {
        $entries = [];

        foreach ($this->getPuzzleIdsForSitemap->withMarketplaceOffers() as $puzzleId) {
            array_push($entries, ...$this->localizedEntries('marketplace_puzzle', [
                'puzzleId' => $puzzleId,
            ]));
        }

        return $this->urlsetResponse($entries);
    }
}
