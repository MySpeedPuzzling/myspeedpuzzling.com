<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetPuzzleSolvers;
use SpeedPuzzling\Web\Query\GetUserSolvedPuzzles;
use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class PuzzleDetailController extends AbstractController
{
    public function __construct(
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private GetPuzzleSolvers  $getPuzzleSolvers,
        readonly private GetUserSolvedPuzzles $getUserSolvedPuzzles,
    ) {
    }

    #[Route(path: ['/puzzle/{puzzleId}', '/skladam-puzzle/{puzzleId}'], name: 'puzzle_detail', methods: ['GET'])]
    public function __invoke(string $puzzleId, #[CurrentUser] UserInterface|null $user): Response
    {
        try {
            $puzzle = $this->getPuzzleOverview->byId($puzzleId);
            $puzzleSolvers = $this->getPuzzleSolvers->byPuzzleId($puzzleId);
        } catch (PuzzleNotFound) {
            $this->addFlash('primary', 'Puzzle, které jste zkoušeli hledat, u nás nejsou!');

            return $this->redirectToRoute('puzzles');
        }

        $soloPuzzleSolvers = array_filter($puzzleSolvers, static fn(PuzzleSolver $solver): bool => $solver->playersCount === 1);
        $groupPuzzleSolvers = array_filter($puzzleSolvers, static fn(PuzzleSolver $solver): bool => $solver->playersCount > 1);

        $userSolvedPuzzles = $this->getUserSolvedPuzzles->byUserId(
            $user?->getUserIdentifier()
        );

        return $this->render('puzzle_detail.html.twig', [
            'puzzle' => $puzzle,
            'solo_puzzle_solvers' => $this->groupSoloPuzzles($soloPuzzleSolvers),
            'group_puzzle_solvers' => $groupPuzzleSolvers,
            'puzzles_solved_by_user' => $userSolvedPuzzles,
        ]);
    }

    /**
     * @param array<PuzzleSolver> $solvers
     * @return array<string, non-empty-array<PuzzleSolver>>
     */
    private function groupSoloPuzzles(array $solvers): array
    {
        $grouped = [];

        foreach ($solvers as $solver) {
            $grouped[$solver->playerId][] = $solver;
        }

        return $grouped;
    }
}
