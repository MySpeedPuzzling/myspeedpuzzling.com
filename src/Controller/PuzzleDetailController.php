<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetPuzzleCollection;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetPuzzleSolvers;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Query\GetUserSolvedPuzzles;
use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Services\PuzzlesSorter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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
        readonly private GetPuzzleCollection $getPuzzleCollection,
        readonly private PuzzlesSorter $puzzlesSorter,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/puzzle/{puzzleId}',
            'en' => '/en/puzzle/{puzzleId}',
        ],
        name: 'puzzle_detail',
    )]
    #[Route(
        path: [
            'cs' => '/skladam-puzzle/{puzzleId}',
            'en' => '/solving-puzzle/{puzzleId}',
        ],
        name: 'puzzle_detail_qr',
    )]
    public function __invoke(string $puzzleId, #[CurrentUser] UserInterface|null $user, Request $request): Response
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
        $puzzleCollections = [];

        if ($playerProfile !== null) {
            $userRanking = $this->getRanking->allForPlayer($playerProfile->playerId);
            $puzzleCollections = $this->getPuzzleCollection->forPlayer($playerProfile->playerId);
        }


        $onlyFirstTimes = (bool) $request->get('sortByFirstTry', false);

        if ($onlyFirstTimes === true) {
            $soloPuzzleSolvers = $this->puzzlesSorter->sortByFirstTry($soloPuzzleSolvers);
            $duoPuzzleSolvers = $this->puzzlesSorter->sortByFirstTry($duoPuzzleSolvers);
            $teamPuzzleSolvers = $this->puzzlesSorter->sortByFirstTry($teamPuzzleSolvers);
        } else {
            $soloPuzzleSolvers = $this->puzzlesSorter->sortByFastest($soloPuzzleSolvers);
            $duoPuzzleSolvers = $this->puzzlesSorter->sortByFastest($duoPuzzleSolvers);
            $teamPuzzleSolvers = $this->puzzlesSorter->sortByFastest($teamPuzzleSolvers);
        }

        return $this->render('puzzle_detail.html.twig', [
            'puzzle' => $puzzle,
            'solo_puzzle_solvers' => $this->puzzlesSorter->groupPlayers($soloPuzzleSolvers),
            'duo_puzzle_solvers' => $this->puzzlesSorter->groupPlayers($duoPuzzleSolvers),
            'team_puzzle_solvers' => $this->puzzlesSorter->groupPlayers($teamPuzzleSolvers),
            'puzzles_solved_by_user' => $userSolvedPuzzles,
            'ranking' => $userRanking,
            'tags' => $this->getTags->forPuzzle($puzzleId),
            'puzzle_collections' => $puzzleCollections,
        ]);
    }
}
