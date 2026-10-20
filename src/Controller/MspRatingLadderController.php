<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetPlayerRatingRanking;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspRatingCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\MspRatingTimeline;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MspRatingLadderController extends AbstractController
{
    private const int PIECES_COUNT = PuzzleIntelligenceRecalculator::RATING_PIECES_COUNTS[0];

    public function __construct(
        readonly private GetPlayerRatingRanking $getPlayerRatingRanking,
        readonly private MspRatingCalculator $mspRatingCalculator,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private ClockInterface $clock,
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
        $ratingProgress = null;
        $isRated = false;
        $rankingOptedOut = false;

        // The viewer's place is the position line of the board itself (MspRatingLadder) - here only what the board
        // cannot say: the progress towards a first rating, and why a rated private profile is not listed
        if ($loggedPlayer !== null) {
            $rankingOptedOut = $loggedPlayer->rankingOptedOut;

            if (!$rankingOptedOut) {
                $isRated = isset($this->getPlayerRatingRanking->allForPlayer($loggedPlayer->playerId)[$piecesCount]);

                if ($isRated === false) {
                    $ratingProgress = $this->mspRatingCalculator->getProgress($loggedPlayer->playerId, $piecesCount);
                }
            }
        }

        return $this->render('msp_rating_ladder/index.html.twig', [
            'pieces_count' => $piecesCount,
            'rating_progress' => $ratingProgress,
            'logged_player' => $loggedPlayer,
            'is_rated' => $isRated,
            'ranking_opted_out' => $rankingOptedOut,
            'min_first_attempts' => MspRatingCalculator::MINIMUM_FIRST_ATTEMPTS,
            'min_total_solves' => MspRatingCalculator::MINIMUM_TOTAL_SOLVES,
            'current_season' => MspRatingTimeline::CURRENT_SEASON,
            'next_season' => MspRatingTimeline::NEXT_SEASON,
            'next_season_starts_on' => MspRatingTimeline::nextSeasonAnnounced($this->clock->now()) ? MspRatingTimeline::nextSeasonStartsOn() : null,
        ]);
    }
}
