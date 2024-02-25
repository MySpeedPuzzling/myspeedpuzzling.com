<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Query\GetStatistics;
use SpeedPuzzling\Web\Query\GetStopwatch;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Services\PuzzlesSorter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class MyProfileController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        readonly private GetStopwatch $getStopwatch,
        readonly private PuzzlesSorter $puzzlesSorter,
        readonly private GetStatistics $getStatistics,
        readonly private GetRanking $getRanking,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetFavoritePlayers $getFavoritePlayers,
        readonly private GetTags $getTags,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/muj-profil',
            'en' => '/en/my-profile',
        ],
        name: 'my_profile',
        methods: ['GET'],
    )]
    public function __invoke(#[CurrentUser] User $user): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('homepage');
        }

        $soloStatistics = $this->getStatistics->soloForPlayer($player->playerId);
        $groupStatistics = $this->getStatistics->inGroupForPlayer($player->playerId);
        $playerStatistics = $soloStatistics->sum($groupStatistics);

        $soloSolvedPuzzles = $this->getPlayerSolvedPuzzles->soloByPlayerId($player->playerId);
        $groupSolvedPuzzles = $this->getPlayerSolvedPuzzles->inGroupByPlayerId($player->playerId);

        return $this->render('my-profile.html.twig', [
            'player' => $player,
            'solo_puzzles' => $this->puzzlesSorter->groupPuzzles($soloSolvedPuzzles),
            'group_puzzles' => $this->puzzlesSorter->groupPuzzles($groupSolvedPuzzles),
            'stopwatches' => $this->getStopwatch->allForPlayer($player->playerId),
            'statistics' => $playerStatistics,
            'ranking' => $this->getRanking->allForPlayer($player->playerId),
            'favorite_players' => $this->getFavoritePlayers->forPlayerId($player->playerId),
            'tags' => $this->getTags->allGroupedPerPuzzle(),
        ]);
    }
}
