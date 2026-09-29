<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\PuzzleDifficultyRankings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Hardest / easiest puzzle lists: every list that currently exists (same
 * availability rule as the pages, which 404 otherwise), in both directions.
 */
final class SitemapDifficultyController extends AbstractController
{
    use SitemapResponseTrait;

    public function __construct(
        readonly private PuzzleDifficultyRankings $puzzleDifficultyRankings,
    ) {
    }

    #[Route(path: '/sitemap-difficulty.xml', name: 'sitemap_difficulty')]
    public function __invoke(): Response
    {
        $availability = $this->puzzleDifficultyRankings->availability();
        $entries = [];

        foreach ($availability->piecesCounts() as $pieces) {
            foreach (['pieces_hardest_puzzles', 'pieces_easiest_puzzles'] as $route) {
                array_push($entries, ...$this->localizedEntries($route, [
                    'pieces' => $pieces,
                ]));
            }
        }

        foreach ($availability->brands as $brand) {
            foreach (['brand_hardest_puzzles', 'brand_easiest_puzzles'] as $route) {
                array_push($entries, ...$this->localizedEntries($route, [
                    'slug' => $brand->slug,
                ]));
            }
        }

        return $this->urlsetResponse($entries);
    }
}
