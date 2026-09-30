<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPlayerIdsForSitemap;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SitemapPlayersController extends AbstractController
{
    use SitemapResponseTrait;

    /**
     * Same chunking as the puzzle sitemaps: 10 000 <url> entries per file,
     * one entry per locale: intdiv(10 000, 6 locales) = 1 666 players/file.
     */
    public const int PLAYERS_PER_PAGE = 1_666;

    public function __construct(
        readonly private GetPlayerIdsForSitemap $getPlayerIdsForSitemap,
    ) {
    }

    #[Route(
        path: '/sitemap-players-{page}.xml',
        name: 'sitemap_players',
        requirements: ['page' => '[1-9]\d*'],
    )]
    public function __invoke(int $page): Response
    {
        $players = $this->getPlayerIdsForSitemap->publicWithResultsPage(
            limit: self::PLAYERS_PER_PAGE,
            offset: ($page - 1) * self::PLAYERS_PER_PAGE,
        );

        if ($players === [] && $page > 1) {
            throw $this->createNotFoundException();
        }

        $entries = [];

        foreach ($players as $player) {
            array_push($entries, ...$this->localizedEntries('player_profile', [
                'playerId' => $player['id'],
            ], $player['lastmod']));
        }

        return $this->urlsetResponse($entries);
    }
}
