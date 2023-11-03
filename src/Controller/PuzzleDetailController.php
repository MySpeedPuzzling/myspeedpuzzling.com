<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetPuzzleSolvers;
use SpeedPuzzling\Web\Results\PuzzleSolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class PuzzleDetailController extends AbstractController
{
    public function __construct(
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private GetPuzzleSolvers  $getPuzzleSolvers,
    )
    {
    }

    #[Route(path: '/puzzle/{puzzleId}', name: 'puzzle_detail', methods: ['GET'])]
    public function __invoke(string $puzzleId): Response
    {
        try {
            $puzzle = $this->getPuzzleOverview->byId($puzzleId);
            $puzzleSolvers = $this->getPuzzleSolvers->byPuzzleId($puzzleId);
        } catch (PuzzleNotFound) {
            $this->addFlash('primary', 'Puzzle, které jste zkoušeli hledat, u nás nejsou!');

            return $this->redirectToRoute('puzzles');
        }

        return $this->render('puzzle_detail.html.twig', [
            'puzzle' => $puzzle,
            'solo_puzzle_solvers' => array_filter($puzzleSolvers, static fn(PuzzleSolver $solver): bool => $solver->playersCount === 1),
            'group_puzzle_solvers' => array_filter($puzzleSolvers, static fn(PuzzleSolver $solver): bool => $solver->playersCount > 1),
        ]);
    }
}
