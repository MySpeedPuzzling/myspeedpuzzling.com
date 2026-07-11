<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SitemapGuidesController extends AbstractController
{
    use SitemapResponseTrait;

    /**
     * Guides are English-only by design: single URL per page, no localized
     * variants (unlike the other child sitemaps which expand to 6 locales).
     *
     * @var list<string>
     */
    private const array GUIDE_ROUTES = [
        'guides',
        'guide_what_is_speed_puzzling',
        'guide_puzzle_time_by_pieces',
        'guide_speed_puzzling_tips',
    ];

    #[Route(path: '/sitemap-guides.xml', name: 'sitemap_guides')]
    public function __invoke(): Response
    {
        $entries = [];

        foreach (self::GUIDE_ROUTES as $route) {
            $entries[] = [
                'loc' => $this->generateUrl($route, [], UrlGeneratorInterface::ABSOLUTE_URL),
                'lastmod' => null,
            ];
        }

        return $this->urlsetResponse($entries);
    }
}
