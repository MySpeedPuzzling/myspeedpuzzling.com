<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetMostActivePlayers;
use SpeedPuzzling\Web\Query\GetMostSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetStatistics;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HubController extends AbstractController
{
    public function __construct(
        readonly private GetStatistics $getStatistics,
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
            'de' => '/de/zentrale',
        ],
        name: 'hub',
    )]
    public function __invoke(): Response
    {
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
