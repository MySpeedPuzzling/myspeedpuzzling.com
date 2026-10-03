<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPlayersPerCountry;
use SpeedPuzzling\Web\Value\CommunityScope;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\PlayersDirectoryCriteria;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A country's puzzlers (docs/features/players-page/README.md, stream S5): the Players page's spotlight on that
 * country, then its directory with the country fixed. Every country code has a page; one without a single public
 * player is thin content - still a 200, but noindex.
 */
final class PlayersPerCountryController extends AbstractController
{
    public function __construct(
        readonly private GetPlayersPerCountry $getPlayersPerCountry,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/hraci-dle-zeme/{countryCode}',
            'en' => '/en/players-from-country/{countryCode}',
            'es' => '/es/jugadores-del-pais/{countryCode}',
            'ja' => '/ja/国別プレイヤー/{countryCode}',
            'fr' => '/fr/joueurs-par-pays/{countryCode}',
            'de' => '/de/spieler-aus-land/{countryCode}',
        ],
        name: 'players_per_country',
    )]
    public function __invoke(string $countryCode, Request $request): Response
    {
        $code = CountryCode::fromCode($countryCode);

        if ($code === null) {
            throw $this->createNotFoundException();
        }

        $scope = CommunityScope::country($code);

        return $this->render('players_per_country.html.twig', [
            'country' => $code,
            'scope' => $scope,
            'criteria' => PlayersDirectoryCriteria::fromQuery($request->query->all(), $scope),
            'has_public_players' => $this->getPlayersPerCountry->hasPublicPlayers($code),
        ]);
    }
}
