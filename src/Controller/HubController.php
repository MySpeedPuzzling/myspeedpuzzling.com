<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetLastSolvedPuzzle;
use SpeedPuzzling\Web\Query\GetMostActivePlayers;
use SpeedPuzzling\Web\Query\GetMostSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetStatistics;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class HubController extends AbstractController
{
    public function __construct(
        readonly private GetLastSolvedPuzzle $getLastSolvedPuzzle,
        readonly private GetStatistics $getStatistics,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetMostSolvedPuzzles $getMostSolvedPuzzles,
        readonly private GetMostActivePlayers $getMostActivePlayers,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/hub',
            'en' => '/en/hub',
            'es' => '/es/centro',
            'ja' => '/ja/ハブ',
            'fr' => '/fr/hub',
        ],
        name: 'hub',
    )]
    public function __invoke(#[CurrentUser] null|UserInterface $user): Response
    {
        $playerProfile = $this->retrieveLoggedUserProfile->getProfile();
        $favoritesSolvedPuzzle = [];

        if ($playerProfile !== null) {
            $favoritesSolvedPuzzle = $this->getLastSolvedPuzzle->ofPlayerFavorites(20, $playerProfile->playerId);
        }

        $thisMonth = (int) date("m");
        $thisYear = (int) date("Y");

        if ($thisMonth === 1) {
            $lastMonth = 12;
            $lastYear = $thisYear - 1;
        } else {
            $lastMonth = $thisMonth - 1;
            $lastYear = $thisYear;
        }

        return $this->render('hub.html.twig', [
            'last_solved_puzzles' => $this->getLastSolvedPuzzle->limit(20),
            'last_solved_favorites_puzzles' => $favoritesSolvedPuzzle,
            'this_month_most_solved_puzzle' => $this->getMostSolvedPuzzles->topInMonth(20, $thisMonth, $thisYear),
            'last_month_most_solved_puzzle' => $this->getMostSolvedPuzzles->topInMonth(20, $lastMonth, $lastYear),
            'all_time_most_solved_puzzle' => $this->getMostSolvedPuzzles->top(20),
            'this_month_most_active_solo_players' => $this->getMostActivePlayers->mostActiveSoloPlayersInMonth(20, $thisMonth, $thisYear),
            'last_month_most_active_solo_players' => $this->getMostActivePlayers->mostActiveSoloPlayersInMonth(20, $lastMonth, $lastYear),
            'all_time_most_active_solo_players' => $this->getMostActivePlayers->mostActiveSoloPlayers(20),
            'this_month_global_statistics' => $this->getStatistics->globallyInMonth($thisMonth, $thisYear),
            'last_month_global_statistics' => $this->getStatistics->globallyInMonth($lastMonth, $lastYear),
            'all_time_global_statistics' => $this->getStatistics->globally(),
        ]);
    }
}
