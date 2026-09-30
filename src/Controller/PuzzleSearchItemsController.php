<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPlayerBestSoloTimes;
use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Results\PiecesFilter;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\PuzzleSearchCriteria;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Appends further result pages for the PuzzleSearch live component: returns
 * only the next page of rendered items (constant cost per click) instead of
 * re-rendering the whole component with a growing limit.
 */
final class PuzzleSearchItemsController extends AbstractController
{
    public function __construct(
        readonly private SearchPuzzle $searchPuzzle,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private GetPlayerBestSoloTimes $getPlayerBestSoloTimes,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetTags $getTags,
        readonly private GetSellSwapListItems $getSellSwapListItems,
        readonly private GetPuzzleDifficulty $getPuzzleDifficulty,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/puzzle-search-items',
            'en' => '/en/puzzle-search-items',
            'es' => '/es/puzzle-search-items',
            'ja' => '/ja/puzzle-search-items',
            'fr' => '/fr/puzzle-search-items',
            'de' => '/de/puzzle-search-items',
        ],
        name: 'puzzle_search_items',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $playerProfile = $this->retrieveLoggedUserProfile->getProfile();

        $criteria = PuzzleSearchCriteria::fromRequest(
            $request,
            isMember: $playerProfile?->activeMembership === true,
            isLoggedIn: $playerProfile !== null,
        );

        $rawOffset = $request->query->get('offset');
        $offset = is_numeric($rawOffset) ? max(0, (int) $rawOffset) : 0;

        $piecesFilter = PiecesRange::fromFilter(PiecesFilter::fromUserInput($criteria->pieces));

        $totalCount = $this->searchPuzzle->countByUserInput(
            $criteria->brandId,
            $criteria->search,
            $piecesFilter,
            $criteria->tagId,
            $criteria->difficultyTiers,
            $criteria->list,
            $playerProfile?->playerId,
        );

        $offset = min($offset, $totalCount);

        $puzzles = $this->searchPuzzle->byUserInput(
            $criteria->brandId,
            $criteria->search,
            $piecesFilter,
            $criteria->tagId,
            $criteria->sortBy,
            offset: $offset,
            limit: PuzzleSearchCriteria::PAGE_SIZE,
            difficultyTiers: $criteria->difficultyTiers,
            list: $criteria->list,
            listPlayerId: $playerProfile?->playerId,
        );

        $puzzleIds = array_map(static fn (PuzzleOverview $puzzle): string => $puzzle->puzzleId, $puzzles);

        // The viewer's best time on these puzzles - not a rank on every puzzle they ever solved
        $myTimes = $playerProfile !== null
            ? $this->getPlayerBestSoloTimes->forPuzzles($playerProfile->playerId, $puzzleIds)
            : [];

        $html = $this->renderView('puzzle/_search_result_items.html.twig', [
            'puzzles' => $puzzles,
            'search' => $criteria->search,
            'puzzle_statuses' => $this->getUserPuzzleStatuses->byPlayerId($playerProfile?->playerId),
            'my_times' => $myTimes,
            'tags' => $this->getTags->allGroupedPerPuzzle($puzzleIds),
            'offer_counts' => $this->getSellSwapListItems->countByPuzzleIds($puzzleIds),
            'difficulty_data' => $this->getPuzzleDifficulty->forPuzzleList($puzzleIds),
        ]);

        $shownCount = $offset + count($puzzles);
        $hasMore = $shownCount < $totalCount;

        return new JsonResponse([
            'html' => $html,
            'hasMore' => $hasMore,
            'nextUrl' => $hasMore
                ? $this->generateUrl('puzzle_search_items', $criteria->toQueryParameters() + ['offset' => $shownCount])
                : null,
            'remainingLabel' => $this->translator->trans('remaining', ['%puzzle%' => max(0, $totalCount - $shownCount)]),
        ]);
    }
}
