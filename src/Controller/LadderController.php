<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetFastestGroups;
use SpeedPuzzling\Web\Query\GetFastestPairs;
use SpeedPuzzling\Web\Query\GetFastestPlayers;
use SpeedPuzzling\Web\Query\GetMostSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetStatistics;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class LadderController extends AbstractController
{
    public function __construct(
        readonly private GetFastestPlayers $getFastestPlayers,
        readonly private GetFastestPairs $getFastestPairs,
        readonly private GetFastestGroups $getFastestGroups,
        readonly private GetMostSolvedPuzzles $getMostSolvedPuzzles,
        readonly private GetStatistics $getStatistics,
    ) {
    }

    #[Route(path: '/zebricek', name: 'ladder', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        return $this->render('ladder.html.twig', [
            'fastest_players_500_pieces' => $this->getFastestPlayers->perPiecesCount(500, 10),
            'fastest_players_1000_pieces' => $this->getFastestPlayers->perPiecesCount(1000, 10),
            'fastest_pairs_500_pieces' => $this->getFastestPairs->perPiecesCount(500, 10),
            'fastest_pairs_1000_pieces' => $this->getFastestPairs->perPiecesCount(1000, 10),
            'fastest_groups_500_pieces' => $this->getFastestGroups->perPiecesCount(500, 10),
            'fastest_groups_1000_pieces' => $this->getFastestGroups->perPiecesCount(1000, 10),
            'most_solved_puzzles' => $this->getMostSolvedPuzzles->top(10),
            'most_active_players' => $this->getStatistics->mostActivePlayers(10),
        ]);
    }
}
