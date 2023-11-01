<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetFastestPlayers;
use SpeedPuzzling\Web\Query\GetMostSolvedPuzzles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class LadderController extends AbstractController
{
    public function __construct(
        readonly private GetFastestPlayers $getFastestPlayers,
        readonly private GetMostSolvedPuzzles $getMostSolvedPuzzles,
    ) {
    }

    #[Route(path: '/zebricek', name: 'ladder', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        return $this->render('ladder.html.twig', [
            'fastest_players_500_pieces' => $this->getFastestPlayers->perPiecesCount(500, 10),
            'most_solved_puzzles' => $this->getMostSolvedPuzzles->top(10),
        ]);
    }
}
