<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\FormData\BorrowPuzzleFormData;
use SpeedPuzzling\Web\FormType\BorrowPuzzleFormType;
use SpeedPuzzling\Web\Message\BorrowPuzzleFrom;
use SpeedPuzzling\Web\Message\BorrowPuzzleTo;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleBorrowingRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Doctrine\ORM\EntityManagerInterface;

#[IsGranted('IS_AUTHENTICATED')]
final class BorrowPuzzleController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PuzzleRepository $puzzleRepository,
        readonly private PlayerRepository $playerRepository,
        readonly private PuzzleBorrowingRepository $borrowingRepository,
        readonly private MessageBusInterface $messageBus,
        readonly private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(
        path: '/en/puzzle/{puzzleId}/borrow',
        name: 'borrow_puzzle',
        methods: ['GET', 'POST'],
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

        // Get borrowing type from query parameter
        $borrowingType = $request->query->getString('type', 'to');
        if (!in_array($borrowingType, ['to', 'from'], true)) {
            $borrowingType = 'to';
        }

        // Check for all active borrowings
        $activeBorrowings = [];
        if ($borrowingType === 'to') {
            $activeBorrowings = $this->borrowingRepository->findAllActiveByOwnerAndPuzzle($player, $puzzle);
        } else {
            $activeBorrowings = $this->borrowingRepository->findAllActiveByBorrowerAndPuzzle($player, $puzzle);
        }

        $formData = new BorrowPuzzleFormData();
        $formData->borrowingType = $borrowingType;

        // Initialize return flags for each existing borrowing
        $returnBorrowingIds = [];
        foreach ($activeBorrowings as $borrowing) {
            $returnBorrowingIds[$borrowing->id->toString()] = true; // Default to checked
        }
        $formData->returnBorrowingIds = $returnBorrowingIds;

        $form = $this->createForm(BorrowPuzzleFormType::class, $formData, [
            'borrowing_type' => $borrowingType,
            'has_active_borrowing' => count($activeBorrowings) > 0,
            'active_borrowings' => $activeBorrowings,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $borrowingId = Uuid::uuid7();

            // Return selected borrowings based on form checkboxes
            $returnedBorrowings = [];
            foreach ($activeBorrowings as $borrowing) {
                $borrowingIdStr = $borrowing->id->toString();
                $fieldName = 'return_' . str_replace('-', '_', $borrowingIdStr);

                if ($form->has($fieldName) && $form->get($fieldName)->getData() === true) {
                    $borrowing->returnPuzzle($player);
                    $returnedBorrowings[] = $borrowingIdStr;
                }
            }
            if (count($returnedBorrowings) > 0) {
                $this->entityManager->flush();
            }

            if ($formData->borrowingType === 'to') {
                // Borrowing TO someone
                $this->messageBus->dispatch(new BorrowPuzzleTo(
                    borrowingId: $borrowingId,
                    puzzleId: $puzzleId,
                    ownerId: $loggedUserProfile->playerId,
                    borrowerId: $formData->person, // Will be validated in handler
                    nonRegisteredPersonName: null, // Handler will determine this
                    returnExistingBorrowing: false, // We already handled returns above
                ));

                if (count($returnedBorrowings) > 0) {
                    $this->addFlash('info', sprintf('%d previous borrowing(s) marked as returned', count($returnedBorrowings)));
                }
                $this->addFlash('success', 'Puzzle marked as borrowed');
            } else {
                // Borrowing FROM someone
                $this->messageBus->dispatch(new BorrowPuzzleFrom(
                    borrowingId: $borrowingId,
                    puzzleId: $puzzleId,
                    borrowerId: $loggedUserProfile->playerId,
                    ownerId: $formData->person, // Will be validated in handler
                    nonRegisteredPersonName: null, // Handler will determine this
                    returnExistingBorrowing: false, // We already handled returns above
                ));

                if (count($returnedBorrowings) > 0) {
                    $this->addFlash('info', sprintf('%d previous borrowing(s) marked as returned', count($returnedBorrowings)));
                }
                $this->addFlash('success', 'Puzzle marked as borrowed');
            }

            return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
        }

        return $this->render('borrow_puzzle.html.twig', [
            'puzzle' => $puzzle,
            'form' => $form,
            'borrowingType' => $borrowingType,
            'activeBorrowings' => $activeBorrowings,
        ]);
    }
}
