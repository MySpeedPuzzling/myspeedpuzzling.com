<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPuzzleIdsForSitemap;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SitemapPuzzlesController extends AbstractController
{
    use SitemapResponseTrait;

    /**
     * Sitemap files are chunked at 10 000 <url> entries; each puzzle emits
     * one entry per locale: intdiv(10 000, 6 locales) = 1 666 puzzles/file.
     */
    public const int PUZZLES_PER_PAGE = 1_666;

    public function __construct(
        readonly private GetPuzzleIdsForSitemap $getPuzzleIdsForSitemap,
    ) {
    }

    #[Route(
        path: '/sitemap-puzzles-{page}.xml',
        name: 'sitemap_puzzles',
        requirements: ['page' => '[1-9]\d*'],
    )]
    public function __invoke(int $page): Response
    {
        $puzzles = $this->getPuzzleIdsForSitemap->approvedPage(
            limit: self::PUZZLES_PER_PAGE,
            offset: ($page - 1) * self::PUZZLES_PER_PAGE,
        );

        if ($puzzles === [] && $page > 1) {
            throw $this->createNotFoundException();
        }

        $entries = [];

        foreach ($puzzles as $puzzle) {
            array_push($entries, ...$this->localizedEntries('puzzle_detail', [
                'puzzleId' => $puzzle['id'],
            ], $puzzle['lastmod']));
        }

        return $this->urlsetResponse($entries);
    }
}
