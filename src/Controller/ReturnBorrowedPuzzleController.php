<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleBorrowingRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Doctrine\ORM\EntityManagerInterface;

#[IsGranted('IS_AUTHENTICATED')]
final class ReturnBorrowedPuzzleController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PuzzleRepository $puzzleRepository,
        readonly private PlayerRepository $playerRepository,
        readonly private PuzzleBorrowingRepository $borrowingRepository,
        readonly private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(
        path: '/en/puzzle/{puzzleId}/return-borrowing',
        name: 'return_borrowed_puzzle',
        methods: ['POST'],
    )]
    public function __invoke(
        string $puzzleId,
        Request $request,
        #[CurrentUser] UserInterface $user
    ): Response {
        $loggedUserProfile = $this->retrieveLoggedUserProfile->getProfile();

        if ($loggedUserProfile === null) {
            throw $this->createAccessDeniedException();
        }

        // Check CSRF token
        $submittedToken = $request->request->getString('_token');
        if (!$this->isCsrfTokenValid('return-borrowing', $submittedToken)) {
            throw $this->createAccessDeniedException();
        }

        try {
            $puzzle = $this->puzzleRepository->get($puzzleId);
        } catch (PuzzleNotFound) {
            throw $this->createNotFoundException();
        }

        try {
            $player = $this->playerRepository->get($loggedUserProfile->playerId);
        } catch (PlayerNotFound) {
            throw $this->createAccessDeniedException();
        }

        // Find active borrowing for this puzzle and player (as owner or borrower)
        $activeBorrowing = $this->borrowingRepository->findActiveBorrowing($player, $puzzle);

        if ($activeBorrowing === null) {
            $this->addFlash('error', 'No active borrowing found for this puzzle');
            return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
        }

        // Return the puzzle
        $activeBorrowing->returnPuzzle($player);
        $this->entityManager->flush();

        $this->addFlash('success', 'Puzzle borrowing has been returned');

        return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
    }
}
