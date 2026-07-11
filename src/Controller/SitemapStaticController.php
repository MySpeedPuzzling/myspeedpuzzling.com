<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SitemapStaticController extends AbstractController
{
    use SitemapResponseTrait;

    /**
     * @var list<string>
     */
    private const array STATIC_ROUTES = [
        'homepage',
        'faq',
        'for_developers',
        'for_organizers',
        'ladder',
        'ladder_solo_500_pieces',
        'ladder_solo_1000_pieces',
        'ladder_pairs_500_pieces',
        'ladder_pairs_1000_pieces',
        'ladder_groups_500_pieces',
        'ladder_groups_1000_pieces',
        'privacy_policy',
        'puzzles',
        'players',
        'recent_activity',
        'terms_of_service',
        'hub',
        'events',
        'marketplace',
        'puzzle_tracker_app',
        'feature_requests',
        'methodology',
        'msp_rating_ladder',
    ];

    #[Route(path: '/sitemap-static.xml', name: 'sitemap_static')]
    public function __invoke(): Response
    {
        // The language crossroads at "/" is a single non-localized page (x-default of the homepage cluster)
        $entries = [
            [
                'loc' => $this->generateUrl('homepage_crossroads', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'lastmod' => null,
            ],
        ];

        foreach (self::STATIC_ROUTES as $route) {
            array_push($entries, ...$this->localizedEntries($route));
        }

        return $this->urlsetResponse($entries);
    }
}
