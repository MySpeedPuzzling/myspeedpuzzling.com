<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\FormData\SearchPlayerFormData;
use SpeedPuzzling\Web\FormType\SearchPlayerFormType;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Query\GetPlayersPerCountry;
use SpeedPuzzling\Web\Query\SearchPlayers;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\SearchQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlayersController extends AbstractController
{
    public function __construct(
        readonly private SearchPlayers $searchPlayers,
        readonly private GetFavoritePlayers $getFavoritePlayers,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPlayersPerCountry $getPlayersPerCountry,
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

        return $this->render('puzzlers.html.twig', [
            'search_form' => $searchForm,
            'found_players' => $foundPlayers,
            'search_string' => $searchString,
            'favorite_players' => $favoritePlayers,
            'most_favorite_players' => $this->getFavoritePlayers->mostFavorite(15),
            'players_per_country' => $this->getPlayersPerCountry->count(),
        ]);
    }
}
