<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetCataloguePuzzles;
use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Results\BrandPiecesHubStats;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Services\CatalogueStatsProvider;
use SpeedPuzzling\Web\Services\PuzzleTimeGuides;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CataloguePagination;
use SpeedPuzzling\Web\Value\PiecesRange;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Brand × piece count ("Ravensburger 500 pieces"): every puzzle of the
 * combination across numbered pages. Exists for the allowed piece counts of
 * the pieces hubs whenever the brand has a visible puzzle of that count;
 * indexable only per BrandPiecesHubStats::isIndexable().
 */
final class BrandPiecesPuzzlesController extends AbstractController
{
    public function __construct(
        readonly private CatalogueStatsProvider $catalogueStatsProvider,
        readonly private GetCataloguePuzzles $getCataloguePuzzles,
        readonly private GetTags $getTags,
        readonly private GetSellSwapListItems $getSellSwapListItems,
        readonly private GetPuzzleDifficulty $getPuzzleDifficulty,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private GetRanking $getRanking,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PuzzleTimeGuides $puzzleTimeGuides,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/puzzle/znacka/{slug}/{pieces}-dilku',
            'en' => '/en/puzzle/brand/{slug}/{pieces}-pieces',
            'es' => '/es/puzzles/marca/{slug}/{pieces}-piezas',
            'ja' => '/ja/パズル/ブランド/{slug}/{pieces}ピース',
            'fr' => '/fr/puzzle/marque/{slug}/{pieces}-pieces',
            'de' => '/de/puzzle/marke/{slug}/{pieces}-teile',
        ],
        name: 'brand_pieces_puzzles',
        requirements: ['slug' => '[a-z0-9\-]+', 'pieces' => '\d{2,5}'],
        priority: 10,
    )]
    #[Route(
        path: [
            'cs' => '/puzzle/znacka/{slug}/{pieces}-dilku/strana/{page}',
            'en' => '/en/puzzle/brand/{slug}/{pieces}-pieces/page/{page}',
            'es' => '/es/puzzles/marca/{slug}/{pieces}-piezas/pagina/{page}',
            'ja' => '/ja/パズル/ブランド/{slug}/{pieces}ピース/ページ/{page}',
            'fr' => '/fr/puzzle/marque/{slug}/{pieces}-pieces/page/{page}',
            'de' => '/de/puzzle/marke/{slug}/{pieces}-teile/seite/{page}',
        ],
        name: 'brand_pieces_puzzles_page',
        requirements: ['slug' => '[a-z0-9\-]+', 'pieces' => '\d{2,5}', 'page' => '[1-9]\d{0,4}'],
        priority: 10,
    )]
    public function __invoke(Request $request, string $slug, int $pieces, int $page = 1): Response
    {
        if (in_array($pieces, PiecesPuzzlesController::ALLOWED_PIECES, true) === false) {
            throw $this->createNotFoundException();
        }

        if ($page === 1 && $request->attributes->get('_route') === 'brand_pieces_puzzles_page') {
            return $this->redirectToRoute('brand_pieces_puzzles', [
                'slug' => $slug,
                'pieces' => $pieces,
                '_locale' => $request->getLocale(),
            ], Response::HTTP_MOVED_PERMANENTLY);
        }

        // Unknown slug: ManufacturerNotFound (404)
        $brand = $this->catalogueStatsProvider->brandHub($slug);
        $piecesRange = PiecesRange::between($pieces, $pieces);
        $puzzlesCount = $this->getCataloguePuzzles->count($brand->brandId, $piecesRange);

        // No visible puzzle of this piece count - there is no such page
        if ($puzzlesCount === 0) {
            throw $this->createNotFoundException();
        }

        $pagination = new CataloguePagination(page: $page, totalItems: $puzzlesCount);

        if ($pagination->exists() === false) {
            throw $this->createNotFoundException();
        }

        // Stats come with the brand hub's cached stats. A combination younger
        // than that cache entry gets its live puzzle count and nothing else
        // yet, which keeps it noindex until the next refresh.
        $stats = $brand->piecesPage($pieces) ?? new BrandPiecesHubStats(
            piecesCount: $pieces,
            puzzlesCount: $puzzlesCount,
            solvesCount: 0,
            medianSeconds: null,
        );

        $puzzles = $this->getCataloguePuzzles->page(
            brandId: $brand->brandId,
            pieces: $piecesRange,
            offset: $pagination->offset(),
            limit: $pagination->perPage,
        );

        $puzzleIds = array_map(
            static fn (PuzzleOverview $puzzle): string => $puzzle->puzzleId,
            $puzzles,
        );

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();

        return $this->render('puzzle/brand_pieces_hub.html.twig', [
            'brand' => $brand,
            'stats' => $stats,
            'indexable' => $stats->isIndexable($brand),
            'pagination' => $pagination,
            'puzzles' => $puzzles,
            'tags' => $this->getTags->allGroupedPerPuzzle($puzzleIds),
            'offer_counts' => $this->getSellSwapListItems->countByPuzzleIds($puzzleIds),
            'difficulty_data' => $this->getPuzzleDifficulty->forPuzzleList($puzzleIds),
            'puzzle_statuses' => $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer?->playerId),
            'ranking' => $loggedPlayer !== null ? $this->getRanking->allForPlayer($loggedPlayer->playerId) : [],
            // "How long does a {N}-piece puzzle take?" - only while that guide is live
            'guide_path' => $pagination->isFirstPage() ? ($this->puzzleTimeGuides->paths()[$pieces] ?? null) : null,
        ]);
    }
}
