<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetCommunityScopeStats;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\ViewerCountry;
use SpeedPuzzling\Web\Value\CommunityScope;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Players page: people discovery for the world or one country (docs/features/players-page/README.md). Every
 * section is its own component that loads its own data; this controller only resolves the scope. The search is the
 * Players:Search live component, which reads `?search=` itself.
 */
final class PlayersController extends AbstractController
{
    public function __construct(
        readonly private GetFavoritePlayers $getFavoritePlayers,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetCommunityScopeStats $getCommunityScopeStats,
        readonly private ViewerCountry $viewerCountry,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/puzzleri',
            'en' => '/en/puzzlers',
            'es' => '/es/jugadores',
            'ja' => '/ja/プレイヤー',
            'fr' => '/fr/joueurs',
            'de' => '/de/puzzler',
        ],
        name: 'players',
    )]
    public function __invoke(Request $request): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        $favoritePlayers = null;

        if ($player !== null) {
            $favoritePlayers = $this->getFavoritePlayers->forPlayerId($player->playerId);
        }

        $scopeCountries = array_values(array_filter(array_map(
            static fn ($statistics): null|CountryCode => $statistics->country(),
            $this->getCommunityScopeStats->countries(),
        )));
        usort($scopeCountries, static fn (CountryCode $a, CountryCode $b): int => strcmp($a->value, $b->value));

        return $this->render('players/index.html.twig', [
            'favorite_players' => $favoritePlayers,
            'scope' => CommunityScope::fromQuery($request->query->get('scope')),
            'home_country' => $this->viewerCountry->fromProfile(),
            'scope_countries' => $scopeCountries,
        ]);
    }
}
