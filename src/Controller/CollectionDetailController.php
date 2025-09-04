<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Entity\PuzzleCollection;
use SpeedPuzzling\Web\Exceptions\PuzzleCollectionNotFound;
use SpeedPuzzling\Web\Query\GetCollectionOverview;
use SpeedPuzzling\Web\Query\GetCollectionPuzzles;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleBorrowingRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class CollectionDetailController extends AbstractController
{
    public function __construct(
        readonly private GetCollectionOverview $getCollectionOverview,
        readonly private GetCollectionPuzzles $getCollectionPuzzles,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PuzzleBorrowingRepository $borrowingRepository,
        readonly private PlayerRepository $playerRepository,
        readonly private PuzzleRepository $puzzleRepository,
    ) {
    }

    #[Route(
        path: '/en/collection/{collectionId}',
        name: 'collection_detail',
    )]
    public function __invoke(string $collectionId, #[CurrentUser] null|UserInterface $user): Response
    {
        try {
            // Handle special collections
            if ($collectionId === 'completed' || $collectionId === 'my-collection') {
                // These require a player context - redirect to home or show error
                return $this->redirectToRoute('homepage');
            }

            $collection = $this->getCollectionOverview->byId($collectionId);
        } catch (PuzzleCollectionNotFound) {
            throw $this->createNotFoundException();
        }

        $loggedUserProfile = null;
        if ($user !== null) {
            $loggedUserProfile = $this->retrieveLoggedUserProfile->getProfile();
        }

        $isOwner = $loggedUserProfile !== null && $loggedUserProfile->playerId === $collection->playerId;

        // Check if collection is accessible
        if (!$collection->isPublic && !$isOwner) {
            return $this->render('collection_private.html.twig', [
                'collection' => $collection,
            ]);
        }

        // Get puzzles based on collection type
        if ($collection->systemType === PuzzleCollection::SYSTEM_COMPLETED) {
            $puzzles = $this->getCollectionPuzzles->byPlayerCompletedPuzzles($collection->playerId);
        } else {
            $puzzles = $this->getCollectionPuzzles->byCollection($collectionId);
        }

        // Get active borrowings for each puzzle if user is owner
        $puzzleBorrowings = [];
        if ($isOwner) {
            try {
                $player = $this->playerRepository->get($loggedUserProfile->playerId);

                foreach ($puzzles as $puzzleData) {
                    try {
                        $puzzle = $this->puzzleRepository->get($puzzleData->puzzleId);
                        $borrowings = $this->borrowingRepository->findAllActiveBorrowingsForPuzzle($player, $puzzle);
                        if (count($borrowings) > 0) {
                            $puzzleBorrowings[$puzzleData->puzzleId] = $borrowings;
                        }
                    } catch (\Exception) {
                        // Skip if puzzle not found
                    }
                }
            } catch (\Exception) {
                // Skip if player not found
            }
        }

        return $this->render('collection_detail.html.twig', [
            'collection' => $collection,
            'puzzles' => $puzzles,
            'isOwner' => $isOwner,
            'puzzleBorrowings' => $puzzleBorrowings,
        ]);
    }
}
