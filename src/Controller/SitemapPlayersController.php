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

    public function __construct(
        readonly private GetPlayerIdsForSitemap $getPlayerIdsForSitemap,
    ) {
    }

    #[Route(path: '/sitemap-players.xml', name: 'sitemap_players')]
    public function __invoke(): Response
    {
        $entries = [];

        foreach ($this->getPlayerIdsForSitemap->allPublic() as $playerId) {
            array_push($entries, ...$this->localizedEntries('player_profile', [
                'playerId' => $playerId,
            ]));
        }

        return $this->urlsetResponse($entries);
    }
}
