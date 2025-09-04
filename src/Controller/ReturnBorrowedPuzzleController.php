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

        // Check if a specific borrowing ID was provided
        $borrowingId = $request->request->getString('borrowing_id');

        if ($borrowingId !== '') {
            // Find specific borrowing by ID
            $activeBorrowing = $this->entityManager->find(\SpeedPuzzling\Web\Entity\PuzzleBorrowing::class, $borrowingId);

            if ($activeBorrowing === null || $activeBorrowing->returnedAt !== null) {
                $this->addFlash('error', 'No active borrowing found with this ID');
                return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
            }

            // Verify the player is involved in this borrowing
            if (
                !($activeBorrowing->owner->id->equals($player->id) ||
                  ($activeBorrowing->borrower !== null && $activeBorrowing->borrower->id->equals($player->id)))
            ) {
                $this->addFlash('error', 'You are not authorized to return this borrowing');
                return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
            }
        } else {
            // Find active borrowing for this puzzle and player (as owner or borrower) - backward compatibility
            $activeBorrowing = $this->borrowingRepository->findActiveBorrowing($player, $puzzle);

            if ($activeBorrowing === null) {
                $this->addFlash('error', 'No active borrowing found for this puzzle');
                return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
            }
        }

        // Return the puzzle
        $activeBorrowing->returnPuzzle($player);
        $this->entityManager->flush();

        $this->addFlash('success', 'Puzzle borrowing has been returned');

        return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
    }
}
