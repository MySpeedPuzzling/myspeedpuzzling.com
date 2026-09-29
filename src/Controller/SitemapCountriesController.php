<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPlayersPerCountry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SitemapCountriesController extends AbstractController
{
    use SitemapResponseTrait;

    public function __construct(
        readonly private GetPlayersPerCountry $getPlayersPerCountry,
    ) {
    }

    #[Route(path: '/sitemap-countries.xml', name: 'sitemap_countries')]
    public function __invoke(): Response
    {
        $entries = [];

        // Only countries whose page lists at least one public player - an empty
        // country page is thin content and carries noindex.
        foreach ($this->getPlayersPerCountry->countriesWithPublicPlayers() as $country) {
            array_push($entries, ...$this->localizedEntries('players_per_country', [
                'countryCode' => $country->name,
            ]));
        }

        return $this->urlsetResponse($entries);
    }
}
