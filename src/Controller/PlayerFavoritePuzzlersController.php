<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A personal page: the players the signed-in player has in favorites, and the players who have them in favorites.
 * Nobody else gets it - whom a player follows, and who follows them, is theirs alone.
 */
final class PlayerFavoritePuzzlersController extends AbstractController
{
    public function __construct(
        readonly private GetFavoritePlayers $getFavoritePlayers,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/oblibeni-hraci/{playerId}',
            'en' => '/en/player-favorites/{playerId}',
            'es' => '/es/favoritos-jugador/{playerId}',
            'ja' => '/ja/プレイヤーお気に入り/{playerId}',
            'fr' => '/fr/favoris-joueur/{playerId}',
            'de' => '/de/spieler-favoriten/{playerId}',
        ],
        name: 'player_favorite_puzzlers',
    )]
    public function __invoke(string $playerId): Response
    {
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        // Guests and other players land on the player's profile, so old links and search results still lead somewhere.
        // A 302, never a 301: the answer depends on who is asking.
        if ($viewer === null || strtolower($playerId) !== strtolower($viewer->playerId)) {
            return $this->redirectToRoute('player_profile', ['playerId' => $playerId]);
        }

        return $this->render('player_favorite_puzzlers.html.twig', [
            'player' => $viewer,
            'favorite_players' => $this->getFavoritePlayers->forPlayerId($viewer->playerId),
            'followers' => $this->getFavoritePlayers->followersOf($viewer->playerId),
        ]);
    }
}
