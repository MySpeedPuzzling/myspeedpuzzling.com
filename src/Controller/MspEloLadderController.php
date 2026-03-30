<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPlayerEloRanking;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspEloCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MspEloLadderController extends AbstractController
{
    private const int PIECES_COUNT = PuzzleIntelligenceRecalculator::ELO_PIECES_COUNTS[0];
    private const int PER_PAGE = 100;

    public function __construct(
        readonly private GetPlayerEloRanking $getPlayerEloRanking,
        readonly private MspEloCalculator $mspEloCalculator,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/msp-elo',
            'en' => '/en/msp-elo',
            'es' => '/es/msp-elo',
            'ja' => '/ja/msp-elo',
            'fr' => '/fr/msp-elo',
            'de' => '/de/msp-elo',
        ],
        name: 'msp_elo_ladder',
    )]
    public function __invoke(): Response
    {
        $piecesCount = self::PIECES_COUNT;

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        $playerPosition = null;
        $playerEloRating = null;
        $eloProgress = null;
        $myPage = null;
        $totalCount = $this->getPlayerEloRanking->totalCount($piecesCount);

        if ($loggedPlayer !== null) {
            $playerPosition = $this->getPlayerEloRanking->playerPosition($loggedPlayer->playerId, $piecesCount);
            $eloProgress = $this->mspEloCalculator->getProgress($loggedPlayer->playerId, $piecesCount);

            $playerEloData = $this->getPlayerEloRanking->allForPlayer($loggedPlayer->playerId);
            $playerEloRating = $playerEloData[$piecesCount]['elo_rating'] ?? null;

            if ($playerPosition !== null) {
                $myPage = (int) ceil($playerPosition / self::PER_PAGE);
            }
        }

        return $this->render('msp_elo_ladder/index.html.twig', [
            'total_count' => $totalCount,
            'pieces_count' => $piecesCount,
            'player_position' => $playerPosition,
            'player_elo_rating' => $playerEloRating,
            'elo_progress' => $eloProgress,
            'logged_player' => $loggedPlayer,
            'my_page' => $myPage,
            'min_first_attempts' => MspEloCalculator::MINIMUM_FIRST_ATTEMPTS,
            'min_total_solves' => MspEloCalculator::MINIMUM_TOTAL_SOLVES,
        ]);
    }
}
