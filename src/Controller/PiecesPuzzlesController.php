<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use SpeedPuzzling\Web\Query\GetPiecesHub;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Results\PiecesHubStats;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class PiecesPuzzlesController extends AbstractController
{
    /**
     * Piece counts that get a hub landing page. Curated from a DB probe
     * (2026-07-11): every value here has >= 50 recorded solves. Standard
     * retail counts plus speed-puzzling staples (49/54/99); oddball counts
     * that also passed the threshold (e.g. 631, 636, 759, 504) are single
     * product artifacts and deliberately excluded. Any other value 404s.
     *
     * @var list<int>
     */
    public const array ALLOWED_PIECES = [49, 54, 99, 100, 150, 200, 250, 300, 350, 500, 750, 1000, 1500, 2000, 3000];

    private const int GRID_LIMIT = 24;

    public function __construct(
        readonly private GetPiecesHub $getPiecesHub,
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
    public function __invoke(int $pieces): Response
    {
        if (in_array($pieces, self::ALLOWED_PIECES, true) === false) {
            throw $this->createNotFoundException();
        }

        // Stats are the same for every visitor - cache them per piece count.
        $stats = $this->cache->get('pieces_hub_stats_' . $pieces, function (ItemInterface $item) use ($pieces): PiecesHubStats {
            $item->expiresAfter(21600); // 6 hours

            return $this->getPiecesHub->stats($pieces);
        });

        $puzzles = $this->getPiecesHub->mostSolvedPuzzles($pieces, self::GRID_LIMIT);

        $puzzleIds = array_map(
            static fn (PuzzleOverview $puzzle): string => $puzzle->puzzleId,
            $puzzles,
        );

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();

        return $this->render('puzzle/pieces_hub.html.twig', [
            'stats' => $stats,
            'puzzles' => $puzzles,
            'tags' => $this->getTags->allGroupedPerPuzzle($puzzleIds),
            'offer_counts' => $this->getSellSwapListItems->countByPuzzleIds($puzzleIds),
            'difficulty_data' => $this->getPuzzleDifficulty->forPuzzleList($puzzleIds),
            'puzzle_statuses' => $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer?->playerId),
            'ranking' => $loggedPlayer !== null ? $this->getRanking->allForPlayer($loggedPlayer->playerId) : [],
            'listing_pieces_param' => self::listingPiecesParameter($pieces),
        ]);
    }

    /**
     * Maps an exact piece count to the closest bucket understood by the
     * puzzle listing's `pieces` query parameter (PiecesFilter).
     */
    private static function listingPiecesParameter(int $pieces): string
    {
        return match (true) {
            $pieces === 500 => '500',
            $pieces === 1000 => '1000',
            $pieces < 500 => '1-499',
            $pieces < 1000 => '501-999',
            default => '1001+',
        };
    }
}
