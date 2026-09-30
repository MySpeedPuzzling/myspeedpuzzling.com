<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetCataloguePuzzles;
use SpeedPuzzling\Web\Query\GetPlayerBestSoloTimes;
use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Services\CatalogueStatsProvider;
use SpeedPuzzling\Web\Services\PuzzleDifficultyRankings;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CataloguePagination;
use SpeedPuzzling\Web\Value\PiecesRange;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Brand hub: every puzzle of the brand across numbered pages (page 1 at the
 * brand's URL, pages 2+ at a /page/{n} path segment - see CataloguePagination).
 */
final class BrandPuzzlesController extends AbstractController
{
    public function __construct(
        readonly private CatalogueStatsProvider $catalogueStatsProvider,
        readonly private GetCataloguePuzzles $getCataloguePuzzles,
        readonly private GetTags $getTags,
        readonly private GetSellSwapListItems $getSellSwapListItems,
        readonly private GetPuzzleDifficulty $getPuzzleDifficulty,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private GetPlayerBestSoloTimes $getPlayerBestSoloTimes,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PuzzleDifficultyRankings $puzzleDifficultyRankings,
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
    #[Route(
        path: [
            'cs' => '/puzzle/znacka/{slug}/strana/{page}',
            'en' => '/en/puzzle/brand/{slug}/page/{page}',
            'es' => '/es/puzzles/marca/{slug}/pagina/{page}',
            'ja' => '/ja/パズル/ブランド/{slug}/ページ/{page}',
            'fr' => '/fr/puzzle/marque/{slug}/page/{page}',
            'de' => '/de/puzzle/marke/{slug}/seite/{page}',
        ],
        name: 'brand_puzzles_page',
        requirements: ['slug' => '[a-z0-9\-]+', 'page' => '[1-9]\d{0,4}'],
        priority: 10,
    )]
    public function __invoke(Request $request, string $slug, int $page = 1): Response
    {
        if ($page === 1 && $request->attributes->get('_route') === 'brand_puzzles_page') {
            return $this->redirectToRoute('brand_puzzles', [
                'slug' => $slug,
                '_locale' => $request->getLocale(),
            ], Response::HTTP_MOVED_PERMANENTLY);
        }

        // Unknown slug: ManufacturerNotFound (404)
        $stats = $this->catalogueStatsProvider->brandHub($slug);

        $pagination = new CataloguePagination(
            page: $page,
            totalItems: $this->getCataloguePuzzles->count($stats->brandId, PiecesRange::any()),
        );

        if ($pagination->exists() === false) {
            throw $this->createNotFoundException();
        }

        $puzzles = $this->getCataloguePuzzles->page(
            brandId: $stats->brandId,
            pieces: PiecesRange::any(),
            offset: $pagination->offset(),
            limit: $pagination->perPage,
        );

        $puzzleIds = array_map(
            static fn (PuzzleOverview $puzzle): string => $puzzle->puzzleId,
            $puzzles,
        );

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();

        return $this->render('puzzle/brand_hub.html.twig', [
            'stats' => $stats,
            'pagination' => $pagination,
            'puzzles' => $puzzles,
            'tags' => $this->getTags->allGroupedPerPuzzle($puzzleIds),
            'offer_counts' => $this->getSellSwapListItems->countByPuzzleIds($puzzleIds),
            'difficulty_data' => $this->getPuzzleDifficulty->forPuzzleList($puzzleIds),
            'puzzle_statuses' => $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer?->playerId),
            // The viewer's best time on the listed puzzles - not a rank on every puzzle they ever solved
            'my_times' => $loggedPlayer !== null ? $this->getPlayerBestSoloTimes->forPuzzles($loggedPlayer->playerId, $puzzleIds) : [],
            'allowed_pieces' => PiecesPuzzlesController::ALLOWED_PIECES,
            // Under the list: the brand's hardest / easiest lists when it has them (cached)
            'has_brand_difficulty_lists' => $this->puzzleDifficultyRankings->availability()->brand($stats->slug) !== null,
        ]);
    }
}
