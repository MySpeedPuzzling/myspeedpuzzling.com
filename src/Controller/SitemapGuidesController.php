<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\PuzzleTimeGuides;
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
        'guide_average_puzzle_time',
        'guide_puzzle_time_pairs_and_teams',
        'guide_speed_puzzling_tips',
    ];

    public function __construct(
        readonly private PuzzleTimeGuides $puzzleTimeGuides,
    ) {
    }

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

        // One "how long does a {N}-piece puzzle take" guide per size that has enough
        // solves to be live - the 1000-piece guide is already listed above.
        foreach ($this->puzzleTimeGuides->absoluteUrls() as $pieces => $url) {
            if ($pieces === PuzzleTimeGuides::ORIGINAL_GUIDE_PIECES) {
                continue;
            }

            $entries[] = [
                'loc' => $url,
                'lastmod' => null,
            ];
        }

        return $this->urlsetResponse($entries);
    }
}
