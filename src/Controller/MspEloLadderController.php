<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPlayerEloRanking;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspEloCalculator;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MspEloLadderController extends AbstractController
{
    private const int PIECES_COUNT = 500;

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
        $period = 'all-time';
        $piecesCount = self::PIECES_COUNT;

        $entries = $this->getPlayerEloRanking->ranking($piecesCount, $period, 50);
        $totalCount = $this->getPlayerEloRanking->totalCount($piecesCount, $period);

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        $playerPosition = null;
        $eloProgress = null;

        if ($loggedPlayer !== null) {
            $playerPosition = $this->getPlayerEloRanking->playerPosition($loggedPlayer->playerId, $piecesCount, $period);
            $eloProgress = $this->mspEloCalculator->getProgress($loggedPlayer->playerId, $piecesCount);
        }

        return $this->render('msp_elo_ladder/index.html.twig', [
            'entries' => $entries,
            'total_count' => $totalCount,
            'pieces_count' => $piecesCount,
            'player_position' => $playerPosition,
            'elo_progress' => $eloProgress,
            'logged_player' => $loggedPlayer,
        ]);
    }
}
