<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPuzzleIdsForSitemap;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SitemapController extends AbstractController
{
    private const array LOCALES = ['cs', 'en', 'es', 'ja', 'fr', 'de'];

    public function __construct(
        readonly private GetPuzzleIdsForSitemap $getPuzzleIdsForSitemap,
    ) {
    }

    #[Route(path: '/sitemap.xml')]
    public function __invoke(): Response
    {
        /** @var array<string, array<string, string>> $urls */
        $urls = [
            'homepage_crossroads' => [
                'en' => $this->generateUrl('homepage_crossroads', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ],
        ];

        $staticRoutes = ['homepage', 'contact', 'faq', 'for_developers', 'ladder', 'ladder_solo_500_pieces', 'ladder_solo_1000_pieces', 'ladder_pairs_500_pieces', 'ladder_pairs_1000_pieces', 'ladder_groups_500_pieces', 'ladder_groups_1000_pieces', 'privacy_policy', 'puzzles', 'players', 'recent_activity', 'terms_of_service', 'hub', 'events', 'marketplace', 'puzzle_tracker_app'];

        foreach ($staticRoutes as $route) {
            foreach (self::LOCALES as $locale) {
                $urls[$route][$locale] = $this->generateUrl($route, ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
            }
        }

        // Fetch puzzle IDs once (not inside locale loop!)
        $puzzleIds = $this->getPuzzleIdsForSitemap->allApproved();

        foreach ($puzzleIds as $puzzleId) {
            foreach (self::LOCALES as $locale) {
                $urls[$puzzleId][$locale] = $this->generateUrl('puzzle_detail', [
                    '_locale' => $locale,
                    'puzzleId' => $puzzleId,
                ], UrlGeneratorInterface::ABSOLUTE_URL);
            }
        }

        $marketplacePuzzleIds = $this->getPuzzleIdsForSitemap->withMarketplaceOffers();

        foreach ($marketplacePuzzleIds as $puzzleId) {
            foreach (self::LOCALES as $locale) {
                $urls['marketplace_' . $puzzleId][$locale] = $this->generateUrl('marketplace_puzzle', [
                    '_locale' => $locale,
                    'puzzleId' => $puzzleId,
                ], UrlGeneratorInterface::ABSOLUTE_URL);
            }
        }

        foreach (CountryCode::cases() as $countryCode) {
            foreach (self::LOCALES as $locale) {
                $urls['players_per_country_' . $countryCode->name][$locale] = $this->generateUrl('players_per_country', [
                    '_locale' => $locale,
                    'countryCode' => $countryCode->name,
                ], UrlGeneratorInterface::ABSOLUTE_URL);
            }
        }

        $response = new Response(headers: [
            'Content-Type' => 'text/xml',
        ]);

        // Cache for 6 hours - sitemap doesn't need real-time updates
        $response->setSharedMaxAge(21600);
        $response->setMaxAge(3600);

        return $this->render('sitemap.xml.twig', [
            'urls' => $urls,
        ], $response);
    }
}
