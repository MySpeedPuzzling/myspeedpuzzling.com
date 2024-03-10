<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\FormData\SearchPuzzleFormData;
use SpeedPuzzling\Web\FormType\SearchPuzzleFormType;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Query\GetUserSolvedPuzzles;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class
PuzzlesController extends AbstractController
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

        $frameId = $request->headers->get('Turbo-Frame');

        if (in_array($frameId, ['search-form', 'search-results'], true)) {
            return $this->render('_puzzle_search_results.html.twig');
        }

        /*
        if ($searchForm->isValid()) {
            $data = $searchForm->getData();
            assert($data instanceof SearchPuzzleFormData);

            return $this->redirectToRoute('puzzles', [
                'pieces' => $data->pieces,
                'brand' => $data->brand,
                'tags' => $data->tags,
                'search' => $data->search,
                'only_with_results' => $data->onlyWithResults,
                'only_solved_by_me' => $data->onlySolvedByMe,
            ]);
        }
        */

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
            $searchData->pieces,
        );

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
            $searchData->pieces,
            $offset,
        );

        $limit = 20;

        return $this->render('puzzles.html.twig', [
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
