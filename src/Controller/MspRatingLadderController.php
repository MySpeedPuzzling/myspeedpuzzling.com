<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPlayerRatingRanking;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspRatingCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MspRatingLadderController extends AbstractController
{
    private const int PIECES_COUNT = PuzzleIntelligenceRecalculator::RATING_PIECES_COUNTS[0];
    private const int PER_PAGE = 100;

    public function __construct(
        readonly private GetPlayerRatingRanking $getPlayerRatingRanking,
        readonly private MspRatingCalculator $mspRatingCalculator,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/msp-rating',
            'en' => '/en/msp-rating',
            'es' => '/es/msp-rating',
            'ja' => '/ja/msp-rating',
            'fr' => '/fr/msp-rating',
            'de' => '/de/msp-rating',
        ],
        name: 'msp_rating_ladder',
    )]
    public function __invoke(): Response
    {
        $piecesCount = self::PIECES_COUNT;

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        $playerPosition = null;
        $playerRating = null;
        $playerTotal = null;
        $ratingProgress = null;
        $myPage = null;

        $rankingOptedOut = false;

        if ($loggedPlayer !== null) {
            $rankingOptedOut = $loggedPlayer->rankingOptedOut;

            if (!$rankingOptedOut) {
                $ratingProgress = $this->mspRatingCalculator->getProgress($loggedPlayer->playerId, $piecesCount);

                // The same "#rank of total" the profile card shows - never a number of its own
                $playerRatingData = $this->getPlayerRatingRanking->allForPlayer($loggedPlayer->playerId)[$piecesCount] ?? null;

                if ($playerRatingData !== null) {
                    $playerRating = $playerRatingData['elo_rating'];
                    $playerPosition = $playerRatingData['rank'];
                    $playerTotal = $playerRatingData['total'];
                    $myPage = (int) ceil($playerPosition / self::PER_PAGE);
                }
            }
        }

        return $this->render('msp_rating_ladder/index.html.twig', [
            'player_total' => $playerTotal,
            'pieces_count' => $piecesCount,
            'player_position' => $playerPosition,
            'player_rating' => $playerRating,
            'rating_progress' => $ratingProgress,
            'logged_player' => $loggedPlayer,
            'my_page' => $myPage,
            'ranking_opted_out' => $rankingOptedOut,
            'min_first_attempts' => MspRatingCalculator::MINIMUM_FIRST_ATTEMPTS,
            'min_total_solves' => MspRatingCalculator::MINIMUM_TOTAL_SOLVES,
        ]);
    }
}
