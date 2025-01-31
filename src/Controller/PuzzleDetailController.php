<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetPlayersPerCountry;
use SpeedPuzzling\Web\Query\GetPuzzleCollection;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetPuzzleSolvers;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Query\GetUserSolvedPuzzles;
use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Results\PuzzleSolversGroup;
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
        readonly private GetPlayersPerCountry $getPlayersPerCountry,
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
            $countries = $this->getPlayersPerCountry->forPuzzle($puzzleId);
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

        $onlyFirstTimes = (bool) $request->get('firstTryOnly', false);

        if ($onlyFirstTimes === true) {
            $soloPuzzleSolvers = $this->puzzlesSorter->sortByFirstTry($soloPuzzleSolvers);
        } else {
            $soloPuzzleSolvers = $this->puzzlesSorter->sortByFastest($soloPuzzleSolvers);
        }

        $duoPuzzleSolvers = $this->puzzlesSorter->sortByFastest($duoPuzzleSolvers);
        $teamPuzzleSolvers = $this->puzzlesSorter->sortByFastest($teamPuzzleSolvers);

        $soloPuzzleSolversGrouped = $this->puzzlesSorter->groupPlayers($soloPuzzleSolvers);
        $totalCount = count($soloPuzzleSolversGrouped);
        $firstTryOnlySoloPuzzleSolversGrouped = $this->puzzlesSorter->filterOutNonFirstTriesGrouped($soloPuzzleSolversGrouped);
        $afterFirstTryFilterCount = count($firstTryOnlySoloPuzzleSolversGrouped);

        if ($onlyFirstTimes === true && $afterFirstTryFilterCount > 0) {
            $soloPuzzleSolversGrouped = $firstTryOnlySoloPuzzleSolversGrouped;
        }


        return $this->render('puzzle_detail.html.twig', [
            'puzzle' => $puzzle,
            'solo_puzzle_solvers' => $soloPuzzleSolversGrouped,
            'duo_puzzle_solvers' => $this->puzzlesSorter->groupPlayers($duoPuzzleSolvers),
            'team_puzzle_solvers' => $this->puzzlesSorter->groupPlayers($teamPuzzleSolvers),
            'puzzles_solved_by_user' => $userSolvedPuzzles,
            'ranking' => $userRanking,
            'tags' => $this->getTags->forPuzzle($puzzleId),
            'puzzle_collections' => $puzzleCollections,
            'first_try_only' => $onlyFirstTimes,
            'filtered_out_puzzlers' => $totalCount - $afterFirstTryFilterCount,
            'first_try_only_count' => $afterFirstTryFilterCount,
        ]);
    }
}
