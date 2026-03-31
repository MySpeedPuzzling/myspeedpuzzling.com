<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Query\GetPlayerEloRanking;
use SpeedPuzzling\Web\Query\GetPlayerPrediction;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPlayerSkill;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspEloCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class AddedTimeRecapController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetPuzzleDifficulty $getPuzzleDifficulty,
        readonly private GetPlayerPrediction $getPlayerPrediction,
        readonly private GetRanking $getRanking,
        readonly private GetPlayerSkill $getPlayerSkill,
        readonly private GetPlayerEloRanking $getPlayerEloRanking,
        readonly private MspEloCalculator $mspEloCalculator,
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
        #[CurrentUser] User $user,
        string $timeId,
    ): Response {
        $solvingPuzzle = $this->getPlayerSolvedPuzzles->byTimeId($timeId);
        $player = $this->getPlayerProfile->byId($solvingPuzzle->playerId);

        $isSolo = $solvingPuzzle->teamId === null;
        $puzzleDifficulty = $this->getPuzzleDifficulty->byPuzzleId($solvingPuzzle->puzzleId);

        $timePrediction = null;
        $ranking = null;
        $puzzleHistory = [];
        $playerSkill = null;
        $eloData = [];
        $eloProgress = null;

        if ($isSolo && $solvingPuzzle->time !== null && !$player->rankingOptedOut) {
            $timePrediction = $this->getPlayerPrediction->forPuzzle($solvingPuzzle->playerId, $solvingPuzzle->puzzleId, excludeTimeId: $timeId);

            $ranking = $this->getRanking->ofPuzzleForPlayer($solvingPuzzle->puzzleId, $solvingPuzzle->playerId);

            $puzzleHistory = $this->getPlayerSolvedPuzzles->soloByPlayerIdAndPuzzleId(
                $solvingPuzzle->playerId,
                $solvingPuzzle->puzzleId,
            );

            $playerSkill = $this->getPlayerSkill->byPlayerIdAndPiecesCount(
                $solvingPuzzle->playerId,
                $solvingPuzzle->piecesCount,
            );

            $eloData = $this->getPlayerEloRanking->allForPlayer($solvingPuzzle->playerId);

            if (!isset($eloData[$solvingPuzzle->piecesCount])) {
                $eloProgress = $this->mspEloCalculator->getProgress(
                    $solvingPuzzle->playerId,
                    $solvingPuzzle->piecesCount,
                );
            }
        }

        return $this->render('added_time_recap.html.twig', [
            'solved_puzzle' => $solvingPuzzle,
            'puzzle_difficulty' => $puzzleDifficulty,
            'time_prediction' => $timePrediction,
            'player' => $player,
            'is_solo' => $isSolo,
            'ranking' => $ranking,
            'puzzle_history' => $puzzleHistory,
            'player_skill' => $playerSkill,
            'elo_data' => $eloData,
            'elo_progress' => $eloProgress,
        ]);
    }
}
