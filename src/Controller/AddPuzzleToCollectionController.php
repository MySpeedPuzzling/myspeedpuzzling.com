<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyInCollection;
use SpeedPuzzling\Web\Exceptions\PuzzleCollectionNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\AddPuzzleToCollection;
use SpeedPuzzling\Web\Message\RemovePuzzleFromCollection;
use SpeedPuzzling\Web\Query\GetPlayerCollections;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleCollectionItemRepository;
use SpeedPuzzling\Web\Repository\PuzzleCollectionRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED')]
final class AddPuzzleToCollectionController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PlayerRepository $playerRepository,
        readonly private PuzzleRepository $puzzleRepository,
        readonly private PuzzleCollectionRepository $collectionRepository,
        readonly private PuzzleCollectionItemRepository $collectionItemRepository,
        readonly private GetPlayerCollections $getPlayerCollections,
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/en/puzzle/{puzzleId}/add-to-collection',
        name: 'add_puzzle_to_collection',
        methods: ['GET', 'POST'],
    )]
    public function __invoke(string $puzzleId, Request $request, #[CurrentUser] UserInterface $user): Response
    {
        $loggedUserProfile = $this->retrieveLoggedUserProfile->getProfile();

        try {
            $puzzle = $this->puzzleRepository->get($puzzleId);
        } catch (PuzzleNotFound) {
            throw $this->createNotFoundException();
        }

        if ($loggedUserProfile === null) {
            throw $this->createAccessDeniedException();
        }

        // Get collections where user can add puzzles (excluding system collections)
        $allCollections = $this->getPlayerCollections->forCollectionSelection($loggedUserProfile->playerId);
        $collections = array_filter($allCollections, fn($collection) => !$collection->isSystemCollection());

        // Check if puzzle already exists in a collection
        $player = $this->playerRepository->get($loggedUserProfile->playerId);
        $puzzleEntity = $this->puzzleRepository->get($puzzleId);
        $existingItem = $this->collectionItemRepository->findByPlayerAndPuzzle($player, $puzzleEntity);

        // Prefill form values if puzzle is already in a non-system collection
        $prefilledCollectionId = null;
        $prefilledComment = '';
        $prefilledPrice = '';
        $prefilledCondition = '';
        $existingCollectionName = null;
        $isInNonSystemCollection = false;

        if ($existingItem !== null) {
            // Check if it's in a non-system collection or in 'my_collection' system type
            $isInNonSystemCollection = $existingItem->collection === null ||
                !$existingItem->collection->isSystemCollection() ||
                $existingItem->collection->systemType === 'my_collection';

            if ($isInNonSystemCollection) {
                $prefilledCollectionId = $existingItem->collection?->id->toString();
                $prefilledComment = $existingItem->comment ?? '';
                $prefilledPrice = $existingItem->price ?? '';
                $prefilledCondition = $existingItem->condition ?? '';
                $existingCollectionName = $existingItem->collection?->getDisplayName() ?? 'My Collection';
            }
        }

        if ($request->isMethod('POST')) {
            $action = $request->request->getString('action', 'add');

            if ($action === 'remove') {
                // Handle removal from collection
                $submittedToken = $request->request->getString('_token');
                if (!$this->isCsrfTokenValid('remove-from-collection', $submittedToken)) {
                    throw $this->createAccessDeniedException();
                }

                if ($existingItem !== null && $existingItem->collection !== null) {
                    $this->messageBus->dispatch(new RemovePuzzleFromCollection(
                        puzzleId: $puzzleId,
                        collectionId: $existingItem->collection->id->toString(),
                        playerId: $loggedUserProfile->playerId,
                    ));

                    $this->addFlash('success', sprintf(
                        'Puzzle removed from "%s"',
                        $existingItem->collection->getDisplayName()
                    ));
                } elseif ($existingItem !== null && $existingItem->collection === null) {
                    // Remove from root collection
                    $this->messageBus->dispatch(new RemovePuzzleFromCollection(
                        puzzleId: $puzzleId,
                        collectionId: null,
                        playerId: $loggedUserProfile->playerId,
                    ));

                    $this->addFlash('success', 'Puzzle removed from My Collection');
                } else {
                    $this->addFlash('info', 'Puzzle is not in any collection');
                }

                return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
            }

            // Handle adding/updating collection
            $collectionId = $request->request->getString('collection_id', '');
            $comment = $request->request->getString('comment', '');
            $price = $request->request->getString('price', '');
            $condition = $request->request->getString('condition', '');

            // Check CSRF token
            $submittedToken = $request->request->getString('_token');
            if (!$this->isCsrfTokenValid('add-to-collection', $submittedToken)) {
                throw $this->createAccessDeniedException();
            }

            try {
                $collection = null;
                if ($collectionId !== '') {
                    $collection = $this->collectionRepository->get($collectionId);

                    // Check ownership
                    if ($collection->player->id->toString() !== $loggedUserProfile->playerId) {
                        throw $this->createAccessDeniedException();
                    }
                }

                // Re-check if puzzle already exists (it was already checked for prefill)
                $existingItem = $this->collectionItemRepository->findByPlayerAndPuzzle($player, $puzzleEntity);

                $wasMoved = false;
                $oldCollectionName = null;

                if ($existingItem !== null) {
                    // Only show "moved" warning if moving between custom collections
                    // (not when moving to/from system collections)
                    $isMovingBetweenCustomCollections =
                        ($existingItem->collection === null || !$existingItem->collection->isSystemCollection()) &&
                        ($collection === null || !$collection->isSystemCollection()) &&
                        $existingItem->collection !== $collection &&
                        !($existingItem->collection === null && $collection === null);

                    if ($isMovingBetweenCustomCollections) {
                        $wasMoved = true;
                        $oldCollectionName = $existingItem->collection?->getDisplayName() ?? 'My Collection';
                    }
                }

                $this->messageBus->dispatch(new AddPuzzleToCollection(
                    itemId: Uuid::uuid7(),
                    puzzleId: $puzzleId,
                    collectionId: $collectionId !== '' ? $collectionId : null,
                    playerId: $loggedUserProfile->playerId,
                    comment: $comment !== '' ? $comment : null,
                    price: $price !== '' ? $price : null,
                    currency: null, // Currency is not handled in this general add to collection
                    condition: $condition !== '' ? $condition : null,
                ));

                if ($wasMoved) {
                    $newCollectionName = $collection?->getDisplayName() ?? 'My Collection';
                    $this->addFlash('warning', sprintf(
                        'Puzzle was moved from "%s" to "%s"',
                        $oldCollectionName,
                        $newCollectionName
                    ));
                } else {
                    $this->addFlash('success', 'Puzzle added to collection');
                }

                return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
            } catch (HandlerFailedException $e) {
                if ($e->getPrevious() instanceof PuzzleAlreadyInCollection) {
                    $this->addFlash('error', 'Puzzle is already in another collection');
                } else {
                    throw $e;
                }
            } catch (PuzzleCollectionNotFound) {
                $this->addFlash('error', 'Collection not found');
            }
        }

        return $this->render('add_puzzle_to_collection.html.twig', [
            'puzzle' => $puzzle,
            'collections' => $collections,
            'prefilledCollectionId' => $prefilledCollectionId,
            'prefilledComment' => $prefilledComment,
            'prefilledPrice' => $prefilledPrice,
            'prefilledCondition' => $prefilledCondition,
            'existingCollectionName' => $existingCollectionName,
            'isInNonSystemCollection' => $isInNonSystemCollection,
        ]);
    }
}
