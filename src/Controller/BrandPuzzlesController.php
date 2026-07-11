<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use SpeedPuzzling\Web\Query\GetBrandHub;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Results\BrandHubStats;
use SpeedPuzzling\Web\Results\PiecesFilter;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class BrandPuzzlesController extends AbstractController
{
    private const int GRID_LIMIT = 24;

    public function __construct(
        readonly private GetBrandHub $getBrandHub,
        readonly private SearchPuzzle $searchPuzzle,
        readonly private GetTags $getTags,
        readonly private GetSellSwapListItems $getSellSwapListItems,
        readonly private GetPuzzleDifficulty $getPuzzleDifficulty,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private GetRanking $getRanking,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private CacheInterface $cache,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/puzzle/znacka/{slug}',
            'en' => '/en/puzzle/brand/{slug}',
            'es' => '/es/puzzles/marca/{slug}',
            'ja' => '/ja/パズル/ブランド/{slug}',
            'fr' => '/fr/puzzle/marque/{slug}',
            'de' => '/de/puzzle/marke/{slug}',
        ],
        name: 'brand_puzzles',
        requirements: ['slug' => '[a-z0-9\-]+'],
        // Must win over puzzle_detail's catch-all {puzzleId} segment.
        priority: 10,
    )]
    public function __invoke(string $slug): Response
    {
        // Stats are the same for every visitor - cache them per slug. An
        // unknown slug throws ManufacturerNotFound (404) inside the callback,
        // which also prevents caching of misses.
        $stats = $this->cache->get('brand_hub_stats_' . $slug, function (ItemInterface $item) use ($slug): BrandHubStats {
            $item->expiresAfter(21600); // 6 hours

            return $this->getBrandHub->bySlug($slug);
        });

        $puzzles = $this->searchPuzzle->byUserInput(
            brandId: $stats->brandId,
            search: null,
            pieces: PiecesFilter::Any,
            tag: null,
            sortBy: 'most-solved',
            offset: 0,
            limit: self::GRID_LIMIT,
        );

        $puzzleIds = array_map(
            static fn (PuzzleOverview $puzzle): string => $puzzle->puzzleId,
            $puzzles,
        );

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();

        return $this->render('puzzle/brand_hub.html.twig', [
            'stats' => $stats,
            'puzzles' => $puzzles,
            'tags' => $this->getTags->allGroupedPerPuzzle($puzzleIds),
            'offer_counts' => $this->getSellSwapListItems->countByPuzzleIds($puzzleIds),
            'difficulty_data' => $this->getPuzzleDifficulty->forPuzzleList($puzzleIds),
            'puzzle_statuses' => $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer?->playerId),
            'ranking' => $loggedPlayer !== null ? $this->getRanking->allForPlayer($loggedPlayer->playerId) : [],
            'allowed_pieces' => PiecesPuzzlesController::ALLOWED_PIECES,
        ]);
    }
}
