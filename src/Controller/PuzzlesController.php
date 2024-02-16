<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPuzzlesOverview;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Query\GetUserSolvedPuzzles;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class PuzzlesController extends AbstractController
{
    public function __construct(
        readonly private GetPuzzlesOverview $getPuzzlesOverview,
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
        methods: ['GET'],
    )]
    public function __invoke(Request $request, #[CurrentUser] UserInterface|null $user): Response
    {
        $userSolvedPuzzles = $this->getUserSolvedPuzzles->byUserId(
            $user?->getUserIdentifier()
        );

        $playerProfile = $this->retrieveLoggedUserProfile->getProfile();

        $userRanking = [];
        if ($playerProfile !== null) {
            $userRanking = $this->getRanking->allForPlayer($playerProfile->playerId);
        }

        return $this->render('puzzles.html.twig', [
            'puzzles' => $this->getPuzzlesOverview->allApprovedOrAddedByPlayer($playerProfile?->playerId),
            'puzzles_solved_by_user' => $userSolvedPuzzles,
            'ranking' => $userRanking,
            'tags' => $this->getTags->allGroupedPerPuzzle(),
        ]);
    }
}
