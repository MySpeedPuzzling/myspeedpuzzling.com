<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyInCollection;
use SpeedPuzzling\Web\Exceptions\PuzzleCollectionNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\AddPuzzleToCollection;
use SpeedPuzzling\Web\Query\GetPlayerCollections;
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
        readonly private PuzzleRepository $puzzleRepository,
        readonly private PuzzleCollectionRepository $collectionRepository,
        readonly private GetPlayerCollections $getPlayerCollections,
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/puzzle/{puzzleId}/pridat-do-kolekce',
            'en' => '/en/puzzle/{puzzleId}/add-to-collection',
        ],
        name: 'add_puzzle_to_collection',
        methods: ['GET', 'POST'],
    )]
    public function __invoke(string $puzzleId, Request $request, #[CurrentUser] UserInterface $user): Response
    {
        $loggedUserProfile = $this->retrieveLoggedUserProfile->getProfile($user);

        try {
            $puzzle = $this->puzzleRepository->get($puzzleId);
        } catch (PuzzleNotFound) {
            throw $this->createNotFoundException();
        }

        // Get collections where user can add puzzles
        $collections = $this->getPlayerCollections->forCollectionSelection($loggedUserProfile->playerId);

        if ($request->isMethod('POST')) {
            $collectionId = $request->request->get('collection_id');
            $comment = $request->request->get('comment');
            $price = $request->request->get('price');
            $condition = $request->request->get('condition');

            // Check CSRF token
            $submittedToken = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('add-to-collection', $submittedToken)) {
                throw $this->createAccessDeniedException();
            }

            try {
                $collection = null;
                if ($collectionId !== null && $collectionId !== '') {
                    $collection = $this->collectionRepository->get($collectionId);

                    // Check ownership
                    if ($collection->player->id->toString() !== $loggedUserProfile->playerId) {
                        throw $this->createAccessDeniedException();
                    }
                }

                $this->messageBus->dispatch(new AddPuzzleToCollection(
                    itemId: Uuid::uuid7(),
                    puzzleId: $puzzleId,
                    collectionId: $collectionId,
                    playerId: $loggedUserProfile->playerId,
                    comment: $comment ?: null,
                    price: $price ?: null,
                    condition: $condition ?: null,
                ));

                $this->addFlash('success', 'Puzzle added to collection');

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