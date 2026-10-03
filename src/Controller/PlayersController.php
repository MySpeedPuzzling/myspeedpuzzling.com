<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\FormData\SearchPlayerFormData;
use SpeedPuzzling\Web\FormType\SearchPlayerFormType;
use SpeedPuzzling\Web\Query\GetCommunityScopeStats;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Query\SearchPlayers;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\ViewerCountry;
use SpeedPuzzling\Web\Value\CommunityScope;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\SearchQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Players page: people discovery for the world or one country (docs/features/players-page/README.md). Every
 * section is its own component that loads its own data; this controller only resolves the scope and the search.
 */
final class PlayersController extends AbstractController
{
    public function __construct(
        readonly private SearchPlayers $searchPlayers,
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
        $searchString = $request->query->get('search');

        $defaultData = new SearchPlayerFormData();
        if (is_string($searchString)) {
            $defaultData->search = $searchString;
        }

        $searchForm = $this->createForm(SearchPlayerFormType::class, $defaultData);
        $searchForm->handleRequest($request);

        if ($searchForm->isSubmitted() && $searchForm->isValid()) {
            $data = $searchForm->getData();

            return $this->redirectToRoute('players', [
                'search' => (new SearchQuery($data->search))->value,
            ]);
        }

        $foundPlayers = [];

        if (is_string($searchString)) {
            $searchQuery = new SearchQuery($searchString);
            $searchString = $searchQuery->value;

            $foundPlayers = $this->searchPlayers->fulltext($searchString);
        } else {
            $searchString = null;
        }

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
            'search_form' => $searchForm,
            'found_players' => $foundPlayers,
            'search_string' => $searchString,
            'favorite_players' => $favoritePlayers,
            'scope' => CommunityScope::fromQuery($request->query->get('scope')),
            'home_country' => $this->viewerCountry->fromProfile(),
            'scope_countries' => $scopeCountries,
        ]);
    }
}
