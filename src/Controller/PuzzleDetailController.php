<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetPendingPuzzleProposals;
use SpeedPuzzling\Web\Query\GetPlayerPrediction;
use SpeedPuzzling\Web\Query\GetPuzzleCollections;
use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetPuzzleRedirect;
use SpeedPuzzling\Web\Query\GetPuzzleSummary;
use SpeedPuzzling\Web\Query\GetRelatedPuzzles;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Results\PuzzleCatalogueLinks;
use SpeedPuzzling\Web\Results\PuzzleMarketplaceOffer;
use SpeedPuzzling\Web\Services\CatalogueStatsProvider;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class PuzzleDetailController extends AbstractController
{
    public function __construct(
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private GetPuzzleRedirect $getPuzzleRedirect,
        readonly private GetTags $getTags,
        readonly private GetPuzzleCollections $getPuzzleCollections,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetSellSwapListItems $getSellSwapListItems,
        readonly private GetPendingPuzzleProposals $getPendingPuzzleProposals,
        readonly private ClockInterface $clock,
        readonly private GetPuzzleDifficulty $getPuzzleDifficulty,
        readonly private GetPlayerPrediction $getPlayerPrediction,
        readonly private GetRelatedPuzzles $getRelatedPuzzles,
        readonly private GetPuzzleSummary $getPuzzleSummary,
        readonly private CatalogueStatsProvider $catalogueStatsProvider,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/puzzle/{puzzleId}',
            'en' => '/en/puzzle/{puzzleId}',
            'es' => '/es/puzzle/{puzzleId}',
            'ja' => '/ja/パズル/{puzzleId}',
            'fr' => '/fr/puzzle/{puzzleId}',
            'de' => '/de/puzzle/{puzzleId}',
        ],
        name: 'puzzle_detail',
    )]
    public function __invoke(string $puzzleId, #[CurrentUser] null|UserInterface $user, Request $request): Response
    {
        try {
            $puzzle = $this->getPuzzleOverview->byId($puzzleId);
        } catch (PuzzleNotFound $exception) {
            $survivorPuzzleId = $this->getPuzzleRedirect->findSurvivorPuzzleId($puzzleId);

            if ($survivorPuzzleId !== null) {
                return $this->redirectToRoute('puzzle_detail', [
                    'puzzleId' => $survivorPuzzleId,
                ], Response::HTTP_MOVED_PERMANENTLY);
            }

            throw $exception;
        }

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer?->playerId);

        $puzzleCollections = [];
        if ($loggedPlayer !== null) {
            $puzzleCollections = $this->getPuzzleCollections->byPlayerAndPuzzle($loggedPlayer->playerId, $puzzleId);
        }

        $isImageHidden = $puzzle->hideImageUntil !== null && $puzzle->hideImageUntil > $this->clock->now();

        $puzzleDifficulty = $this->getPuzzleDifficulty->byPuzzleId($puzzleId);

        $timePrediction = null;
        if ($loggedPlayer !== null && $loggedPlayer->activeMembership && !$loggedPlayer->timePredictionsOptedOut) {
            $timePrediction = $this->getPlayerPrediction->forPuzzle($loggedPlayer->playerId, $puzzleId);
        }

        // Only a brand × pieces page can be at stake, and the brand hub stats
        // (which tell whether it is indexable) are cached per brand for 6 hours
        $brandHub = null;
        if ($puzzle->manufacturerSlug !== null && in_array($puzzle->piecesCount, PiecesPuzzlesController::ALLOWED_PIECES, true)) {
            $brandHub = $this->catalogueStatsProvider->brandHub($puzzle->manufacturerSlug);
        }

        $marketplaceOffers = $this->getSellSwapListItems->marketplaceOffersByPuzzleId($puzzleId);

        // "More … puzzles" and "About this puzzle" are for search engines and guests only: a signed-in player has
        // every fact higher up (Details, the leaderboard strip) and finds puzzles their own ways (Jan, 2026-10-02).
        // Crawlers come signed out, so they see exactly what a guest sees
        $showSeoSections = $user === null;

        return $this->render('puzzle_detail.html.twig', [
            'puzzle' => $puzzle,
            'puzzle_statuses' => $puzzleStatuses,
            'tags' => $this->getTags->forPuzzle($puzzleId),
            'puzzle_collections' => $puzzleCollections,
            'logged_player' => $loggedPlayer,
            'offers_count' => $this->getSellSwapListItems->countByPuzzleId($puzzleId),
            'marketplace_offers' => $marketplaceOffers,
            'lowest_offer_prices' => PuzzleMarketplaceOffer::lowestPricePerCurrency($marketplaceOffers),
            'has_pending_proposals' => $this->getPendingPuzzleProposals->hasPendingForPuzzle($puzzleId),
            'is_image_hidden' => $isImageHidden,
            'puzzle_difficulty' => $puzzleDifficulty,
            'time_prediction' => $timePrediction,
            'show_seo_sections' => $showSeoSections,
            'related' => $showSeoSections ? $this->getRelatedPuzzles->forPuzzle($puzzle->manufacturerId, $puzzle->piecesCount, $puzzleId) : null,
            'puzzle_summary' => $this->getPuzzleSummary->forPuzzle($puzzleId),
            'catalogue_links' => PuzzleCatalogueLinks::forPuzzle($puzzle, $brandHub),
        ]);
    }
}
