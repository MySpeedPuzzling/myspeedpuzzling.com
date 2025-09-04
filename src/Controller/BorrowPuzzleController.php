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

#[IsGranted('IS_AUTHENTICATED')]
final class BorrowPuzzleController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PuzzleRepository $puzzleRepository,
        readonly private PlayerRepository $playerRepository,
        readonly private PuzzleBorrowingRepository $borrowingRepository,
        readonly private MessageBusInterface $messageBus,
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

        // Check if there's an active borrowing
        $activeBorrowing = null;
        if ($borrowingType === 'to') {
            $activeBorrowing = $this->borrowingRepository->findActiveByOwnerAndPuzzle($player, $puzzle);
        } else {
            $activeBorrowing = $this->borrowingRepository->findActiveByBorrowerAndPuzzle($player, $puzzle);
        }

        $formData = new BorrowPuzzleFormData();
        $formData->borrowingType = $borrowingType;

        $form = $this->createForm(BorrowPuzzleFormType::class, $formData, [
            'borrowing_type' => $borrowingType,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $borrowingId = Uuid::uuid7();

            if ($formData->borrowingType === 'to') {
                // Borrowing TO someone
                $this->messageBus->dispatch(new BorrowPuzzleTo(
                    borrowingId: $borrowingId,
                    puzzleId: $puzzleId,
                    ownerId: $loggedUserProfile->playerId,
                    borrowerId: $formData->person, // Will be validated in handler
                    nonRegisteredPersonName: null, // Handler will determine this
                ));

                if ($activeBorrowing !== null) {
                    $this->addFlash('warning', 'Previous borrowing was returned before creating new one');
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
                ));

                if ($activeBorrowing !== null) {
                    $this->addFlash('warning', 'Previous borrowing was returned before creating new one');
                }
                $this->addFlash('success', 'Puzzle marked as borrowed');
            }

            return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
        }

        return $this->render('borrow_puzzle.html.twig', [
            'puzzle' => $puzzle,
            'form' => $form,
            'borrowingType' => $borrowingType,
            'activeBorrowing' => $activeBorrowing,
        ]);
    }
}
