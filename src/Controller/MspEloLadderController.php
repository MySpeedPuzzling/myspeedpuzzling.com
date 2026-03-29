<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPlayerEloRanking;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspEloCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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
    public function __invoke(Request $request): Response
    {
        $period = 'all-time';
        $piecesCount = self::PIECES_COUNT;

        $page = max(1, $request->query->getInt('page', 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $entries = $this->getPlayerEloRanking->ranking($piecesCount, $period, self::PER_PAGE, $offset);
        $totalCount = $this->getPlayerEloRanking->totalCount($piecesCount, $period);
        $totalPages = max(1, (int) ceil($totalCount / self::PER_PAGE));

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        $playerPosition = null;
        $playerEloRating = null;
        $eloProgress = null;

        if ($loggedPlayer !== null) {
            $playerPosition = $this->getPlayerEloRanking->playerPosition($loggedPlayer->playerId, $piecesCount, $period);
            $eloProgress = $this->mspEloCalculator->getProgress($loggedPlayer->playerId, $piecesCount);

            $playerEloData = $this->getPlayerEloRanking->allForPlayer($loggedPlayer->playerId, $period);
            $playerEloRating = $playerEloData[$piecesCount]['elo_rating'] ?? null;
        }

        return $this->render('msp_elo_ladder/index.html.twig', [
            'entries' => $entries,
            'total_count' => $totalCount,
            'pieces_count' => $piecesCount,
            'player_position' => $playerPosition,
            'player_elo_rating' => $playerEloRating,
            'elo_progress' => $eloProgress,
            'logged_player' => $loggedPlayer,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'min_first_attempts' => MspEloCalculator::MINIMUM_FIRST_ATTEMPTS,
            'min_total_solves' => MspEloCalculator::MINIMUM_TOTAL_SOLVES,
        ]);
    }
}
