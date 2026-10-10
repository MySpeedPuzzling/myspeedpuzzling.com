<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
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
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerSkillCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Value\RecapProgress;
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
        readonly private PlayerSkillCalculator $playerSkillCalculator,
        readonly private GetSolvingTimePrediction $getSolvingTimePrediction,
        readonly private GetPlayerDuplicateCases $getPlayerDuplicateCases,
        readonly private ResultAutoRemovalRepository $autoRemovalRepository,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private ClockInterface $clock,
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
        $viewer = $this->retrieveLoggedUserProfile->getProfile();
        $viewerIsMember = $viewer?->activeMembership === true;

        $isTimedSolo = $solvingPuzzle->players === null && $solvingPuzzle->time !== null;
        $puzzleDifficulty = $this->getPuzzleDifficulty->byPuzzleId($solvingPuzzle->puzzleId);

        $timePrediction = null;
        $ranking = null;
        $puzzleHistory = [];

        // The progress card's competition line - MSP Rating and skill tier exist for some piece counts only (500 today),
        // and are members-only; free players get one locked line instead
        $hasRating = in_array($solvingPuzzle->piecesCount, PuzzleIntelligenceRecalculator::RATING_PIECES_COUNTS, true);
        $hasSkillTier = in_array($solvingPuzzle->piecesCount, PuzzleIntelligenceRecalculator::SKILL_PIECES_COUNTS, true);
        $rating = null;
        $ratingProgress = null;
        $playerSkill = null;
        $skillQualifyingCount = null;

        if ($isTimedSolo && !$player->timePredictionsOptedOut) {
            // What was predicted for this solve, stored when it was added - computing it now would see the
            // new time already folded into the baseline and difficulty
            $timePrediction = $this->getSolvingTimePrediction->resultForTime($timeId);
        }

        if ($isTimedSolo && !$player->rankingOptedOut) {
            $ranking = $this->getRanking->ofPuzzleForPlayer($solvingPuzzle->puzzleId, $solvingPuzzle->playerId);

            $puzzleHistory = $this->getPlayerSolvedPuzzles->soloByPlayerIdAndPuzzleId(
                $solvingPuzzle->playerId,
                $solvingPuzzle->puzzleId,
            );

            if ($viewerIsMember && $hasSkillTier) {
                $playerSkill = $this->getPlayerSkill->byPlayerIdAndPiecesCount(
                    $solvingPuzzle->playerId,
                    $solvingPuzzle->piecesCount,
                );

                if ($playerSkill === null) {
                    $skillQualifyingCount = $this->playerSkillCalculator->qualifyingPuzzlesCount(
                        $solvingPuzzle->playerId,
                        $solvingPuzzle->piecesCount,
                    );
                }
            }

            if ($viewerIsMember && $hasRating) {
                $rating = $this->getPlayerRatingRanking->allForPlayer($solvingPuzzle->playerId)[$solvingPuzzle->piecesCount] ?? null;

                if ($rating === null) {
                    $ratingProgress = $this->mspRatingCalculator->getProgress(
                        $solvingPuzzle->playerId,
                        $solvingPuzzle->piecesCount,
                    );
                }
            }
        }

        // Saved twice? The detection right after the save already stored it (docs/features/duplicate-results.md)
        $duplicates = $viewer !== null ? $this->getPlayerDuplicateCases->openOfTime($viewer->playerId, $timeId) : [];

        return $this->render('added_time_recap.html.twig', [
            'duplicates' => $duplicates,
            'viewer_player_id' => $viewer?->playerId,
            'viewer_is_member' => $viewerIsMember,
            'solved_puzzle' => $solvingPuzzle,
            'puzzle_difficulty' => $puzzleDifficulty,
            'time_prediction' => $timePrediction,
            'player' => $player,
            'is_timed_solo' => $isTimedSolo,
            'ranking' => $ranking,
            'faster_than_percent' => $ranking !== null ? RecapProgress::fasterThanPercent($ranking->rank, $ranking->totalPlayers) : null,
            'puzzle_history' => $puzzleHistory,
            'has_rating' => $hasRating,
            'has_skill_tier' => $hasSkillTier,
            'player_skill' => $playerSkill,
            'skill_qualifying_count' => $skillQualifyingCount,
            'skill_minimum' => PlayerSkillCalculator::MINIMUM_QUALIFYING_PUZZLES,
            'rating' => $rating,
            'rating_progress' => $ratingProgress,
            'celebrate' => $solvingPuzzle->trackedAt !== null && RecapProgress::isFresh($solvingPuzzle->trackedAt, $this->clock->now()),
        ]);
    }
}
