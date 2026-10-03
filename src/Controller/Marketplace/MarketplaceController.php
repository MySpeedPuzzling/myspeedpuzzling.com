<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Marketplace;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetMarketplaceEventsHintState;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\IsHintDismissed;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\HintType;
use SpeedPuzzling\Web\Value\MarketplaceEventsBanner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MarketplaceController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private IsHintDismissed $isHintDismissed,
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private GetMarketplaceEventsHintState $getMarketplaceEventsHintState,
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
    public function __invoke(Request $request, string $puzzleId = ''): Response
    {
        $disclaimerDismissed = false;
        $eventsHintState = null;
        $eventsBanner = null;
        // "Pick up at an event" is a filtered view of the marketplace - kept out of the index, links followed
        $eventFilter = $request->query->all()['event'] ?? '';

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        if ($loggedPlayer !== null) {
            // Both banners of the page in one query; the events banner's state only while it is not dismissed
            $dismissed = $this->isHintDismissed->dismissedAmong(
                $loggedPlayer->playerId,
                HintType::MarketplaceDisclaimer,
                HintType::MarketplaceAtEvents,
            );
            $disclaimerDismissed = in_array(HintType::MarketplaceDisclaimer, $dismissed, true);

            if (in_array(HintType::MarketplaceAtEvents, $dismissed, true) === false) {
                $eventsHintState = $this->getMarketplaceEventsHintState->forPlayer($loggedPlayer->playerId);
                $eventsBanner = $eventsHintState->banner($loggedPlayer->activeMembership);

                // "See what's coming" while already looking at it
                if (
                    $eventsBanner === MarketplaceEventsBanner::Buyer
                    && is_string($eventFilter)
                    && strtolower($eventFilter) === $eventsHintState->nearestEvent?->competitionId
                ) {
                    $eventsBanner = null;
                }
            }
        }

        $puzzleOverview = null;
        if ($puzzleId !== '' && Uuid::isValid($puzzleId)) {
            try {
                $puzzleOverview = $this->getPuzzleOverview->byId($puzzleId);
            } catch (PuzzleNotFound) {
                // Puzzle not found, show generic marketplace
            }
        }

        if ($puzzleId !== '' && !Uuid::isValid($puzzleId)) {
            $puzzleId = '';
        }

        return $this->render('marketplace/index.html.twig', [
            'disclaimer_dismissed' => $disclaimerDismissed,
            'puzzle_id' => $puzzleId,
            'puzzle_overview' => $puzzleOverview,
            'events_banner' => $eventsBanner,
            'events_hint_state' => $eventsHintState,
            'noindex' => $eventFilter !== '',
        ]);
    }
}
