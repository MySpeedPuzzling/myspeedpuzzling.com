<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPuzzlesOverview;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Query\GetUserSolvedPuzzles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class PuzzlesController extends AbstractController
{
    public function __construct(
        readonly private GetPuzzlesOverview $getPuzzlesOverview,
        readonly private GetUserSolvedPuzzles $getUserSolvedPuzzles,
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetRanking $getRanking,
    ) {
    }

    #[Route(path: '/puzzle', name: 'puzzles', methods: ['GET'])]
    public function __invoke(Request $request, #[CurrentUser] UserInterface|null $user): Response
    {
        $userSolvedPuzzles = $this->getUserSolvedPuzzles->byUserId(
            $user?->getUserIdentifier()
        );

        $userRanking = [];

        if ($user !== null) {
            $playerProfile = $this->getPlayerProfile->byUserId($user->getUserIdentifier());
            $userRanking = $this->getRanking->allForPlayer($playerProfile->playerId);
        }

        return $this->render('puzzles.html.twig', [
            'puzzles' => $this->getPuzzlesOverview->all(),
            'puzzles_solved_by_user' => $userSolvedPuzzles,
            'ranking' => $userRanking,
        ]);
    }
}
