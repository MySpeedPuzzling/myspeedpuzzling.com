<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\FormData\SearchPuzzleFormData;
use SpeedPuzzling\Web\FormType\SearchPuzzleFormType;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Results\PiecesFilter;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\UX\Turbo\TurboBundle;

final class PuzzlesController extends AbstractController
{
    public function __construct(
        readonly private SearchPuzzle $searchPuzzle,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private GetRanking $getRanking,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetTags $getTags,
        readonly private CacheInterface $cache,
        readonly private GetSellSwapListItems $getSellSwapListItems,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/puzzle',
            'en' => '/en/puzzle',
            'es' => '/es/puzzles',
            'ja' => '/ja/パズル',
            'fr' => '/fr/puzzle',
            'de' => '/de/puzzle',
        ],
        name: 'puzzles',
    )]
    public function __invoke(Request $request, #[CurrentUser] null|UserInterface $user): Response
    {
        $searchData = SearchPuzzleFormData::fromRequest($request);

        $searchForm = $this->createForm(SearchPuzzleFormType::class, $searchData);
        $searchForm->handleRequest($request);

        $playerProfile = $this->retrieveLoggedUserProfile->getProfile();

        $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($playerProfile?->playerId);

        $userRanking = [];
        if ($playerProfile !== null) {
            $userRanking = $this->getRanking->allForPlayer($playerProfile->playerId);
        }

        $rawOffset = $request->query->get('offset');
        $offset = 0;

        if (is_numeric($rawOffset)) {
            $offset = max(0, (int) $rawOffset);
        }

        if ($this->isDefaultSearch($searchData, $offset)) {
            $cached = $this->getInitialPuzzlesFromCache();
            $foundPuzzle = $cached['puzzles'];
            $totalPuzzlesCount = $cached['count'];
        } else {
            $totalPuzzlesCount = $this->searchPuzzle->countByUserInput(
                $searchData->brand,
                $searchData->search,
                PiecesFilter::fromUserInput($searchData->pieces),
                $searchData->tag,
            );

            $offset = min($offset, $totalPuzzlesCount);

            $foundPuzzle = $this->searchPuzzle->byUserInput(
                $searchData->brand,
                $searchData->search,
                PiecesFilter::fromUserInput($searchData->pieces),
                $searchData->tag,
                $searchData->sortBy,
                $offset,
            );
        }

        $templateName = 'puzzles.html.twig';

        $search = $request->query->get('search');

        if ((is_string($search) || $offset !== 0) && $request->headers->has('x-turbo-request-id')) {
            $templateName = '_puzzle_search_results.html.twig';

            if ($offset !== 0) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);
                $templateName = '_puzzle_search_results.stream.html.twig';
            }
        }

        $limit = 20;

        $usingSearch = is_string($search);

        $puzzleIds = array_map(static fn (PuzzleOverview $p): string => $p->puzzleId, $foundPuzzle);
        $offerCounts = $this->getSellSwapListItems->countByPuzzleIds($puzzleIds);

        return $this->render($templateName, [
            'puzzles' => $foundPuzzle,
            'total_puzzles_count' => $totalPuzzlesCount,
            'puzzle_statuses' => $puzzleStatuses,
            'ranking' => $userRanking,
            'tags' => $this->getTags->allGroupedPerPuzzle(),
            'search_form' => $searchForm,
            'form_data' => $searchData,
            'current_offset' => $offset,
            'next_offset' => $offset + $limit,
            'remaining' => max($totalPuzzlesCount - $limit - $offset, 0),
            'using_search' => $usingSearch,
            'offer_counts' => $offerCounts,
        ]);
    }

    private function isDefaultSearch(SearchPuzzleFormData $searchData, int $offset): bool
    {
        return $searchData->brand === null
            && $searchData->search === null
            && $searchData->tag === null
            && $searchData->pieces === null
            && $searchData->sortBy === 'most-solved'
            && $offset === 0;
    }

    /**
     * @return array{puzzles: array<PuzzleOverview>, count: int}
     */
    private function getInitialPuzzlesFromCache(): array
    {
        return $this->cache->get('initial_puzzles_v1', function (ItemInterface $item): array {
            $item->expiresAfter(3600);

            $pieces = PiecesFilter::fromUserInput(null);

            return [
                'puzzles' => $this->searchPuzzle->byUserInput(null, null, $pieces, null, 'most-solved', 0),
                'count' => $this->searchPuzzle->countByUserInput(null, null, $pieces, null),
            ];
        });
    }
}
