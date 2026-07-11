<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPuzzleIdsForSitemap;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SitemapIndexController extends AbstractController
{
    use SitemapResponseTrait;

    public function __construct(
        readonly private GetPuzzleIdsForSitemap $getPuzzleIdsForSitemap,
    ) {
    }

    #[Route(path: '/sitemap.xml', name: 'sitemap_index')]
    public function __invoke(): Response
    {
        $sitemaps = [
            $this->generateUrl('sitemap_static', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ];

        $puzzlePages = max(1, (int) ceil(
            $this->getPuzzleIdsForSitemap->countApproved() / SitemapPuzzlesController::PUZZLES_PER_PAGE,
        ));

        for ($page = 1; $page <= $puzzlePages; $page++) {
            $sitemaps[] = $this->generateUrl('sitemap_puzzles', [
                'page' => $page,
            ], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        $imagePages = max(1, (int) ceil(
            $this->getPuzzleIdsForSitemap->countApprovedWithImages() / SitemapImagesController::IMAGES_PER_PAGE,
        ));

        for ($page = 1; $page <= $imagePages; $page++) {
            $sitemaps[] = $this->generateUrl('sitemap_images', [
                'page' => $page,
            ], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        foreach (['sitemap_marketplace', 'sitemap_events', 'sitemap_players', 'sitemap_feature_requests', 'sitemap_countries', 'sitemap_brands', 'sitemap_guides'] as $route) {
            $sitemaps[] = $this->generateUrl($route, [], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        return $this->xmlResponse('sitemap_index.xml.twig', [
            'sitemaps' => $sitemaps,
        ]);
    }
}
