<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Marketplace;

use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\IsHintDismissed;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\HintType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MarketplaceController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private IsHintDismissed $isHintDismissed,
        readonly private GetPuzzleOverview $getPuzzleOverview,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/marketplace',
            'en' => '/en/marketplace',
            'es' => '/es/marketplace',
            'ja' => '/ja/marketplace',
            'fr' => '/fr/marketplace',
            'de' => '/de/marketplace',
        ],
        name: 'marketplace',
        methods: ['GET'],
    )]
    #[Route(
        path: [
            'cs' => '/marketplace/puzzle/{puzzleId}',
            'en' => '/en/marketplace/puzzle/{puzzleId}',
            'es' => '/es/marketplace/puzzle/{puzzleId}',
            'ja' => '/ja/marketplace/puzzle/{puzzleId}',
            'fr' => '/fr/marketplace/puzzle/{puzzleId}',
            'de' => '/de/marketplace/puzzle/{puzzleId}',
        ],
        name: 'marketplace_puzzle',
        methods: ['GET'],
    )]
    public function __invoke(string $puzzleId = ''): Response
    {
        $disclaimerDismissed = false;

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        if ($loggedPlayer !== null) {
            $disclaimerDismissed = ($this->isHintDismissed)($loggedPlayer->playerId, HintType::MarketplaceDisclaimer);
        }

        $puzzleOverview = null;
        if ($puzzleId !== '') {
            try {
                $puzzleOverview = $this->getPuzzleOverview->byId($puzzleId);
            } catch (PuzzleNotFound) {
                // Puzzle not found, show generic marketplace
            }
        }

        return $this->render('marketplace/index.html.twig', [
            'disclaimer_dismissed' => $disclaimerDismissed,
            'puzzle_id' => $puzzleId,
            'puzzle_overview' => $puzzleOverview,
        ]);
    }
}
