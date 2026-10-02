<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Component\Security\Core\User\UserInterface;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Query\GetPlayerDuplicateCases;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPlayerRatingRanking;
use SpeedPuzzling\Web\Query\GetPlayerSkill;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetSolvingTimePrediction;
use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Repository\ResultAutoRemovalRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspRatingCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class AddedTimeRecapController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetPuzzleDifficulty $getPuzzleDifficulty,
        readonly private GetRanking $getRanking,
        readonly private GetPlayerSkill $getPlayerSkill,
        readonly private GetPlayerRatingRanking $getPlayerRatingRanking,
        readonly private MspRatingCalculator $mspRatingCalculator,
        readonly private GetSolvingTimePrediction $getSolvingTimePrediction,
        readonly private GetPlayerDuplicateCases $getPlayerDuplicateCases,
        readonly private ResultAutoRemovalRepository $autoRemovalRepository,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/cas-pridan/{timeId}',
            'en' => '/en/time-added/{timeId}',
            'es' => '/es/tiempo-anadido/{timeId}',
            'ja' => '/ja/時間追加済み/{timeId}',
            'fr' => '/fr/temps-ajoute/{timeId}',
            'de' => '/de/zeit-hinzugefuegt/{timeId}',
        ],
        name: 'added_time_recap',
    )]
    public function __invoke(
        Request $request,
        #[CurrentUser] UserInterface $user,
        string $timeId,
    ): Response {
        try {
            $solvingPuzzle = $this->getPlayerSolvedPuzzles->byTimeId($timeId);
        } catch (PuzzleSolvingTimeNotFound $exception) {
            // A copy removed automatically lives on as the copy that was kept (docs/features/duplicate-results.md)
            $keptTimeId = $this->autoRemovalRepository->findKeptTimeIdOf($timeId);

            if ($keptTimeId === null) {
                throw $exception;
            }

            // Temporary: Undo brings the removed id back, so no cache may remember the redirect for good
            return $this->redirectToRoute('added_time_recap', ['timeId' => $keptTimeId], Response::HTTP_FOUND);
        }

        $player = $this->getPlayerProfile->byId($solvingPuzzle->playerId);

        $isSolo = $solvingPuzzle->players === null;
        $puzzleDifficulty = $this->getPuzzleDifficulty->byPuzzleId($solvingPuzzle->puzzleId);

        $timePrediction = null;
        $ranking = null;
        $puzzleHistory = [];
        $playerSkill = null;
        $ratingData = [];
        $ratingProgress = null;

        if ($isSolo && $solvingPuzzle->time !== null && !$player->timePredictionsOptedOut) {
            // What was predicted for this solve, stored when it was added - computing it now would see the
            // new time already folded into the baseline and difficulty
            $timePrediction = $this->getSolvingTimePrediction->resultForTime($timeId);
        }

        if ($isSolo && $solvingPuzzle->time !== null && !$player->rankingOptedOut) {
            $ranking = $this->getRanking->ofPuzzleForPlayer($solvingPuzzle->puzzleId, $solvingPuzzle->playerId);

            $puzzleHistory = $this->getPlayerSolvedPuzzles->soloByPlayerIdAndPuzzleId(
                $solvingPuzzle->playerId,
                $solvingPuzzle->puzzleId,
            );

            $playerSkill = $this->getPlayerSkill->byPlayerIdAndPiecesCount(
                $solvingPuzzle->playerId,
                $solvingPuzzle->piecesCount,
            );

            $ratingData = $this->getPlayerRatingRanking->allForPlayer($solvingPuzzle->playerId);

            if (!isset($ratingData[$solvingPuzzle->piecesCount])) {
                $ratingProgress = $this->mspRatingCalculator->getProgress(
                    $solvingPuzzle->playerId,
                    $solvingPuzzle->piecesCount,
                );
            }
        }

        // Saved twice? The detection right after the save already stored it (docs/features/duplicate-results.md)
        $viewer = $this->retrieveLoggedUserProfile->getProfile();
        $duplicates = $viewer !== null ? $this->getPlayerDuplicateCases->openOfTime($viewer->playerId, $timeId) : [];

        return $this->render('added_time_recap.html.twig', [
            'duplicates' => $duplicates,
            'viewer_player_id' => $viewer?->playerId,
            'solved_puzzle' => $solvingPuzzle,
            'puzzle_difficulty' => $puzzleDifficulty,
            'time_prediction' => $timePrediction,
            'player' => $player,
            'is_solo' => $isSolo,
            'ranking' => $ranking,
            'puzzle_history' => $puzzleHistory,
            'player_skill' => $playerSkill,
            'elo_data' => $ratingData,
            'elo_progress' => $ratingProgress,
        ]);
    }
}
