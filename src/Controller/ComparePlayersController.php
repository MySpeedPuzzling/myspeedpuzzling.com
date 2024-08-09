<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Services\PlayersComparison;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ComparePlayersController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetRanking $getRanking,
        readonly private PlayersComparison $playersComparison,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/porovnat-s-puzzlerem/{opponentPlayerId}/',
            'en' => '/compare-with-puzzler/{opponentPlayerId}/',
        ],
        name: 'compare_players',
    )]
    public function __invoke(#[CurrentUser] User $user, string $opponentPlayerId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }

        try {
            $opponent = $this->getPlayerProfile->byId($opponentPlayerId);
            $playerRanking = $this->getRanking->allForPlayer($player->playerId);
            $opponentRanking = $this->getRanking->allForPlayer($opponent->playerId);
        } catch (PlayerNotFound) {
            throw $this->createNotFoundException();
        }

        $comparisons = $this->playersComparison->compare($playerRanking, $opponentRanking);

        return $this->render('compare_players.html.twig', [
            'player' => $player,
            'opponent' => $opponent,
            'comparisons' => $comparisons,
        ]);
    }
}
