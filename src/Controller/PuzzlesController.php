<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\FormData\SearchPuzzleFormData;
use SpeedPuzzling\Web\FormType\SearchPuzzleFormType;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Query\GetUserSolvedPuzzles;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Results\PiecesFilter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\UX\Turbo\TurboBundle;

final class PuzzlesController extends AbstractController
{
    public function __construct(
        readonly private SearchPuzzle $searchPuzzle,
        readonly private GetUserSolvedPuzzles $getUserSolvedPuzzles,
        readonly private GetRanking $getRanking,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetTags $getTags,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/puzzle',
            'en' => '/en/puzzle',
        ],
        name: 'puzzles',
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, #[CurrentUser] UserInterface|null $user): Response
    {
        $searchData = SearchPuzzleFormData::fromRequest($request);

        $searchForm = $this->createForm(SearchPuzzleFormType::class, $searchData);
        $searchForm->handleRequest($request);

        $userSolvedPuzzles = $this->getUserSolvedPuzzles->byUserId(
            $user?->getUserIdentifier()
        );

        $playerProfile = $this->retrieveLoggedUserProfile->getProfile();

        $userRanking = [];
        if ($playerProfile !== null) {
            $userRanking = $this->getRanking->allForPlayer($playerProfile->playerId);
        }

        $totalPuzzlesCount = $this->searchPuzzle->countByUserInput(
            $searchData->brand,
            $searchData->search,
            $searchData->onlyWithResults,
            PiecesFilter::fromUserInput($searchData->pieces),
            $searchData->onlyAvailable,
        );

        /** @var null|int $offset */
        $offset = $request->query->get('offset');
        if (is_numeric($offset)) {
            $offset = max(0, (int) $offset);
            $offset = min($offset, $totalPuzzlesCount);
        } else {
            $offset = 0;
        }

        $foundPuzzle = $this->searchPuzzle->byUserInput(
            $searchData->brand,
            $searchData->search,
            $searchData->onlyWithResults,
            PiecesFilter::fromUserInput($searchData->pieces),
            $searchData->onlyAvailable,
            $offset,
        );

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

        return $this->render($templateName, [
            'puzzles' => $foundPuzzle,
            'total_puzzles_count' => $totalPuzzlesCount,
            'puzzles_solved_by_user' => $userSolvedPuzzles,
            'ranking' => $userRanking,
            'tags' => $this->getTags->allGroupedPerPuzzle(),
            'search_form' => $searchForm,
            'form_data' => $searchData,
            'current_offset' => $offset,
            'next_offset' => $offset + $limit,
        ]);
    }
}
