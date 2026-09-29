<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetCataloguePuzzles;
use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
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
 * Pieces hub: every puzzle with the piece count across numbered pages (page 1
 * at the hub's URL, pages 2+ at a /page/{n} path segment - see CataloguePagination).
 */
final class PiecesPuzzlesController extends AbstractController
{
    /**
     * Piece counts that get a hub landing page. Curated from a DB probe
     * (2026-07-11): every value here has >= 50 recorded solves. Standard
     * retail counts plus speed-puzzling staples (49/54/99); oddball counts
     * that also passed the threshold (e.g. 631, 636, 759, 504) are single
     * product artifacts and deliberately excluded. Any other value 404s.
     * The brand × pieces pages use the same list.
     *
     * @var list<int>
     */
    public const array ALLOWED_PIECES = [49, 54, 99, 100, 150, 200, 250, 300, 350, 500, 750, 1000, 1500, 2000, 3000];

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
            'cs' => '/puzzle/{pieces}-dilku',
            'en' => '/en/puzzle/{pieces}-pieces',
            'es' => '/es/puzzles/{pieces}-piezas',
            'ja' => '/ja/パズル/{pieces}ピース',
            'fr' => '/fr/puzzle/{pieces}-pieces',
            'de' => '/de/puzzle/{pieces}-teile',
        ],
        name: 'pieces_puzzles',
        requirements: ['pieces' => '\d{2,5}'],
        // Must win over puzzle_detail's catch-all {puzzleId} segment
        // (e.g. /en/puzzle/1000-pieces would otherwise match puzzle_detail).
        priority: 10,
    )]
    #[Route(
        path: [
            'cs' => '/puzzle/{pieces}-dilku/strana/{page}',
            'en' => '/en/puzzle/{pieces}-pieces/page/{page}',
            'es' => '/es/puzzles/{pieces}-piezas/pagina/{page}',
            'ja' => '/ja/パズル/{pieces}ピース/ページ/{page}',
            'fr' => '/fr/puzzle/{pieces}-pieces/page/{page}',
            'de' => '/de/puzzle/{pieces}-teile/seite/{page}',
        ],
        name: 'pieces_puzzles_page',
        requirements: ['pieces' => '\d{2,5}', 'page' => '[1-9]\d{0,4}'],
        priority: 10,
    )]
    public function __invoke(Request $request, int $pieces, int $page = 1): Response
    {
        if (in_array($pieces, self::ALLOWED_PIECES, true) === false) {
            throw $this->createNotFoundException();
        }

        if ($page === 1 && $request->attributes->get('_route') === 'pieces_puzzles_page') {
            return $this->redirectToRoute('pieces_puzzles', [
                'pieces' => $pieces,
                '_locale' => $request->getLocale(),
            ], Response::HTTP_MOVED_PERMANENTLY);
        }

        $piecesRange = PiecesRange::between($pieces, $pieces);

        $pagination = new CataloguePagination(
            page: $page,
            totalItems: $this->getCataloguePuzzles->count(null, $piecesRange),
        );

        if ($pagination->exists() === false) {
            throw $this->createNotFoundException();
        }

        $stats = $this->catalogueStatsProvider->piecesHub($pieces);

        $puzzles = $this->getCataloguePuzzles->page(
            brandId: null,
            pieces: $piecesRange,
            offset: $pagination->offset(),
            limit: $pagination->perPage,
        );

        $puzzleIds = array_map(
            static fn (PuzzleOverview $puzzle): string => $puzzle->puzzleId,
            $puzzles,
        );

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();

        return $this->render('puzzle/pieces_hub.html.twig', [
            'stats' => $stats,
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
