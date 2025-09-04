<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyInCollection;
use SpeedPuzzling\Web\Exceptions\PuzzleCollectionNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\AddPuzzleToCollection;
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

        // Get collections where user can add puzzles
        $collections = $this->getPlayerCollections->forCollectionSelection($loggedUserProfile->playerId);

        if ($request->isMethod('POST')) {
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

                // Check if puzzle already exists in a collection
                $player = $this->playerRepository->get($loggedUserProfile->playerId);
                $puzzleEntity = $this->puzzleRepository->get($puzzleId);
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
        ]);
    }
}
