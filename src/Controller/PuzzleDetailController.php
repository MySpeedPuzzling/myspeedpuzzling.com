<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetPuzzleSolvers;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Query\GetUserSolvedPuzzles;
use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PuzzleDetailController extends AbstractController
{
    public function __construct(
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private GetPuzzleSolvers  $getPuzzleSolvers,
        readonly private GetUserSolvedPuzzles $getUserSolvedPuzzles,
        readonly private GetRanking $getRanking,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
        readonly private GetTags $getTags,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/puzzle/{puzzleId}',
            'en' => '/en/puzzle/{puzzleId}',
        ],
        name: 'puzzle_detail',
        methods: ['GET'],
    )]
    #[Route(
        path: [
            'cs' => '/skladam-puzzle/{puzzleId}',
            'en' => '/solving-puzzle/{puzzleId}',
        ],
        name: 'puzzle_detail_qr',
        methods: ['GET'],
    )]
    public function __invoke(string $puzzleId, #[CurrentUser] UserInterface|null $user): Response
    {
        try {
            $puzzle = $this->getPuzzleOverview->byId($puzzleId);
            $soloPuzzleSolvers = $this->getPuzzleSolvers->soloByPuzzleId($puzzleId);
            $duoPuzzleSolvers = $this->getPuzzleSolvers->duoByPuzzleId($puzzleId);
            $teamPuzzleSolvers = $this->getPuzzleSolvers->teamByPuzzleId($puzzleId);
        } catch (PuzzleNotFound) {
            $this->addFlash('primary', $this->translator->trans('flashes.puzzle_not_found'));

            return $this->redirectToRoute('puzzles');
        }

        $userSolvedPuzzles = $this->getUserSolvedPuzzles->byUserId(
            $user?->getUserIdentifier()
        );


        $playerProfile = $this->retrieveLoggedUserProfile->getProfile();
        $userRanking = [];

        if ($playerProfile !== null) {
            $userRanking = $this->getRanking->allForPlayer($playerProfile->playerId);
        }

        return $this->render('puzzle_detail.html.twig', [
            'puzzle' => $puzzle,
            'solo_puzzle_solvers' => $this->aggregateSoloPuzzles($soloPuzzleSolvers),
            'duo_puzzle_solvers' => $duoPuzzleSolvers,
            'team_puzzle_solvers' => $teamPuzzleSolvers,
            'puzzles_solved_by_user' => $userSolvedPuzzles,
            'ranking' => $userRanking,
            'tags' => $this->getTags->forPuzzle($puzzleId),
        ]);
    }

    /**
     * @param array<PuzzleSolver> $solvers
     * @return array<string, non-empty-array<PuzzleSolver>>
     */
    private function aggregateSoloPuzzles(array $solvers): array
    {
        $grouped = [];

        foreach ($solvers as $solver) {
            $grouped[$solver->playerId][] = $solver;
        }

        foreach ($grouped as $puzzleId => $puzzles) {
            // Find the puzzle with the lowest time and place it at the beginning
            usort($puzzles, static fn(PuzzleSolver $a, PuzzleSolver $b): int => $a->time <=> $b->time);
            $fastestPuzzle = array_shift($puzzles);

            // Sort the remaining puzzles by finishedAt
            usort($puzzles, static fn(PuzzleSolver $a, PuzzleSolver $b): int => $b->finishedAt <=> $a->finishedAt);

            // Prepend the fastest puzzle to the sorted array
            array_unshift($puzzles, $fastestPuzzle);

            // Update the group with the sorted puzzles
            $grouped[$puzzleId] = $puzzles;
        }

        return $grouped;
    }
}
