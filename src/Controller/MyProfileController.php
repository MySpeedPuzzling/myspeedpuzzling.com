<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Controller\Api\GetPlayerStatisticsController;
use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Query\GetLastSolvedPuzzle;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetPlayerStatistics;
use SpeedPuzzling\Web\Query\GetPuzzleCollection;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Query\GetStatistics;
use SpeedPuzzling\Web\Query\GetStopwatch;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Query\GetWjpcParticipants;
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
        readonly private GetRanking $getRanking,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetFavoritePlayers $getFavoritePlayers,
        readonly private GetTags $getTags,
        readonly private GetLastSolvedPuzzle $getLastSolvedPuzzle,
        readonly private GetBadges $getBadges,
        readonly private GetPuzzleCollection $getPuzzleCollection,
        readonly private GetWjpcParticipants $getWjpcParticipants,
        readonly private GetPlayerStatistics $getPlayerStatistics
    ) {
    }

    #[Route(
        path: [
            'cs' => '/muj-profil',
            'en' => '/en/my-profile',
        ],
        name: 'my_profile',
    )]
    public function __invoke(#[CurrentUser] User $user): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('homepage');
        }

        $soloStatistics = $this->getPlayerStatistics->solo($player->playerId);
        $groupStatistics = $this->getPlayerStatistics->team($player->playerId);
        $playerStatistics = $soloStatistics->sum($groupStatistics);

        $soloSolvedPuzzles = $this->getPlayerSolvedPuzzles->soloByPlayerId($player->playerId);
        $duoSolvedPuzzles = $this->getPlayerSolvedPuzzles->duoByPlayerId($player->playerId);
        $teamSolvedPuzzles = $this->getPlayerSolvedPuzzles->teamByPlayerId($player->playerId);

        return $this->render('my-profile.html.twig', [
            'player' => $player,
            'last_solved_puzzles' => $this->getLastSolvedPuzzle->forPlayer($player->playerId, 20),
            'solo_results' => $this->puzzlesSorter->groupPuzzles($soloSolvedPuzzles),
            'duo_results' => $this->puzzlesSorter->groupPuzzles($duoSolvedPuzzles),
            'team_results' => $this->puzzlesSorter->groupPuzzles($teamSolvedPuzzles),
            'stopwatches' => $this->getStopwatch->allForPlayer($player->playerId),
            'statistics' => $playerStatistics,
            'ranking' => $this->getRanking->allForPlayer($player->playerId),
            'favorite_players' => $this->getFavoritePlayers->forPlayerId($player->playerId),
            'tags' => $this->getTags->allGroupedPerPuzzle(),
            'badges' => $this->getBadges->forPlayer($player->playerId),
            'puzzle_collections' => $this->getPuzzleCollection->forPlayer($player->playerId),
            'wjpc_participant' => $this->getWjpcParticipants->forPlayer($player->playerId),
        ]);
    }
}
