<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleCollection;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\AddPuzzleToCollection;
use SpeedPuzzling\Web\Message\CreatePuzzleCollection;
use SpeedPuzzling\Web\Message\RemovePuzzleFromCollection;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleCollectionItemRepository;
use SpeedPuzzling\Web\Repository\PuzzleCollectionRepository;
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
final class QuickCollectionActionController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PlayerRepository $playerRepository,
        readonly private PuzzleRepository $puzzleRepository,
        readonly private PuzzleCollectionRepository $collectionRepository,
        readonly private PuzzleCollectionItemRepository $collectionItemRepository,
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/puzzle/{puzzleId}/rychla-akce-kolekce',
            'en' => '/en/puzzle/{puzzleId}/quick-collection-action',
        ],
        name: 'quick_collection_action',
        methods: ['POST'],
    )]
    public function __invoke(string $puzzleId, Request $request, #[CurrentUser] UserInterface $user): Response
    {
        $loggedUserProfile = $this->retrieveLoggedUserProfile->getProfile();

        if ($loggedUserProfile === null) {
            throw $this->createAccessDeniedException();
        }

        try {
            $puzzle = $this->puzzleRepository->get($puzzleId);
        } catch (PuzzleNotFound) {
            throw $this->createNotFoundException();
        }

        $action = $request->request->getString('action', '');
        $systemType = $request->request->getString('system_type', '');

        // Check CSRF token
        $submittedToken = $request->request->getString('_token');
        if (!$this->isCsrfTokenValid('quick-collection-action', $submittedToken)) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->playerRepository->get($loggedUserProfile->playerId);

        // Map system types to collection types
        $collectionTypeMap = [
            'wishlist' => PuzzleCollection::SYSTEM_WISHLIST,
            'todolist' => PuzzleCollection::SYSTEM_TODO,
            'for_sale' => PuzzleCollection::SYSTEM_FOR_SALE,
            'borrowed_to' => PuzzleCollection::SYSTEM_BORROWED_TO,
            'borrowed_from' => PuzzleCollection::SYSTEM_BORROWED_FROM,
        ];

        if (!isset($collectionTypeMap[$systemType])) {
            throw $this->createNotFoundException('Invalid collection type');
        }

        $collectionType = $collectionTypeMap[$systemType];

        if ($action === 'add') {
            // Find or create the system collection
            $collection = $this->collectionRepository->findSystemCollection($player, $collectionType);

            if ($collection === null) {
                // Create the system collection
                $collectionId = Uuid::uuid7();
                $collectionName = match ($collectionType) {
                    PuzzleCollection::SYSTEM_WISHLIST => 'Wishlist',
                    PuzzleCollection::SYSTEM_TODO => 'To Do List',
                    PuzzleCollection::SYSTEM_FOR_SALE => 'For Sale',
                    PuzzleCollection::SYSTEM_BORROWED_TO => 'Borrowed To Others',
                    PuzzleCollection::SYSTEM_BORROWED_FROM => 'Borrowed From Others',
                };

                $this->messageBus->dispatch(new CreatePuzzleCollection(
                    collectionId: $collectionId,
                    playerId: $loggedUserProfile->playerId,
                    name: $collectionName,
                    description: null,
                    isPublic: in_array($collectionType, [PuzzleCollection::SYSTEM_FOR_SALE], true),
                    systemType: $collectionType,
                ));

                $collection = $this->collectionRepository->get($collectionId->toString());
            }

            // Check if puzzle is already in this collection
            $existingItem = $this->collectionItemRepository->findByPlayerAndPuzzle($player, $puzzle);
            if ($existingItem !== null && $existingItem->collection === $collection) {
                $this->addFlash('info', 'Puzzle is already in ' . $collection->getDisplayName());
            } else {
                // Add puzzle to collection
                $this->messageBus->dispatch(new AddPuzzleToCollection(
                    itemId: Uuid::uuid7(),
                    puzzleId: $puzzleId,
                    collectionId: $collection->id->toString(),
                    playerId: $loggedUserProfile->playerId,
                    comment: null,
                    price: null,
                    condition: null,
                ));

                if ($existingItem !== null) {
                    // Only show "moved" warning if moving between custom collections
                    $isMovingBetweenCustomCollections = 
                        ($existingItem->collection === null || !$existingItem->collection->isSystemCollection()) &&
                        !$collection->isSystemCollection();
                    
                    if ($isMovingBetweenCustomCollections) {
                        $oldCollectionName = $existingItem->collection?->getDisplayName() ?? 'My Collection';
                        $this->addFlash('warning', sprintf(
                            'Puzzle moved from "%s" to "%s"',
                            $oldCollectionName,
                            $collection->getDisplayName()
                        ));
                    } else {
                        $this->addFlash('success', 'Added to ' . $collection->getDisplayName());
                    }
                } else {
                    $this->addFlash('success', 'Added to ' . $collection->getDisplayName());
                }
            }
        } elseif ($action === 'remove') {
            // Find the system collection
            $collection = $this->collectionRepository->findSystemCollection($player, $collectionType);

            if ($collection !== null) {
                // Find and remove the item
                $existingItem = $this->collectionItemRepository->findByPlayerAndPuzzle($player, $puzzle);

                if ($existingItem !== null && $existingItem->collection === $collection) {
                    $this->messageBus->dispatch(new RemovePuzzleFromCollection(
                        puzzleId: $puzzleId,
                        collectionId: $collection->id->toString(),
                        playerId: $loggedUserProfile->playerId,
                    ));

                    $this->addFlash('success', 'Removed from ' . $collection->getDisplayName());
                } else {
                    $this->addFlash('info', 'Puzzle was not in ' . $collection->getDisplayName());
                }
            } else {
                $this->addFlash('info', 'Collection does not exist');
            }
        } else {
            throw $this->createNotFoundException('Invalid action');
        }

        return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
    }
}
