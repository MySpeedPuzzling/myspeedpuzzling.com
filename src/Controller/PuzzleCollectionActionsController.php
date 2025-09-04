<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetCollectionPuzzles;
use SpeedPuzzling\Web\Repository\PuzzleBorrowingRepository;
use SpeedPuzzling\Web\Repository\PuzzleCollectionRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class PuzzleCollectionActionsController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PuzzleRepository $puzzleRepository,
        readonly private GetCollectionPuzzles $getCollectionPuzzles,
        readonly private PuzzleBorrowingRepository $borrowingRepository,
        readonly private PlayerRepository $playerRepository,
        readonly private PuzzleCollectionRepository $collectionRepository,
    ) {
    }

    #[Route(
        path: '/en/puzzle/{puzzleId}/collection-actions',
        name: 'puzzle_collection_actions',
    )]
    public function __invoke(string $puzzleId, #[CurrentUser] null|UserInterface $user): Response
    {
        try {
            $puzzle = $this->puzzleRepository->get($puzzleId);
        } catch (PuzzleNotFound) {
            throw $this->createNotFoundException();
        }

        $loggedUserProfile = null;
        $userCollections = [];
        $userCollectionDetails = [];
        $activeBorrowings = [];

        if ($user !== null) {
            $loggedUserProfile = $this->retrieveLoggedUserProfile->getProfile();
            if ($loggedUserProfile !== null) {
                // Get which collections this puzzle is already in for this user
                $userCollections = $this->getCollectionPuzzles->getPuzzleCollections(
                    $loggedUserProfile->playerId,
                    $puzzleId
                );

                // For non-system collections (UUIDs), fetch the actual collection details
                foreach ($userCollections as $collectionKey) {
                    // Check if it's a UUID (non-system collection)
                    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $collectionKey)) {
                        try {
                            $collection = $this->collectionRepository->get($collectionKey);
                            $userCollectionDetails[$collectionKey] = $collection->getDisplayName();
                        } catch (\Exception) {
                            // If collection not found, use generic name
                            $userCollectionDetails[$collectionKey] = 'Custom Collection';
                        }
                    }
                }

                // Check for active borrowings
                try {
                    $player = $this->playerRepository->get($loggedUserProfile->playerId);
                    $allBorrowings = $this->borrowingRepository->findAllActiveBorrowingsForPuzzle($player, $puzzle);

                    // Separate borrowings by type
                    foreach ($allBorrowings as $borrowing) {
                        $borrowingType = null;
                        if ($borrowing->owner->id->equals($player->id) && !$borrowing->borrowedFrom) {
                            $borrowingType = 'owner';
                        } elseif ($borrowing->borrower !== null && $borrowing->borrower->id->equals($player->id) && $borrowing->borrowedFrom) {
                            $borrowingType = 'borrower';
                        } elseif ($borrowing->owner->id->equals($player->id) && $borrowing->borrowedFrom) {
                            // Player borrowed from someone
                            $borrowingType = 'borrower';
                        }

                        if ($borrowingType !== null) {
                            $activeBorrowings[] = [
                                'borrowing' => $borrowing,
                                'type' => $borrowingType,
                            ];
                        }
                    }
                } catch (\Exception) {
                    // If player not found or any error, just continue without borrowing info
                }
            }
        }

        // Check if puzzle is in root collection or any custom collection
        $isInCollection = false;
        foreach ($userCollections as $collectionKey) {
            if ($collectionKey === 'my_collection' || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $collectionKey)) {
                $isInCollection = true;
                break;
            }
        }

        return $this->render('components/_puzzle_collection_actions.html.twig', [
            'puzzle' => $puzzle,
            'userCollections' => $userCollections,
            'userCollectionDetails' => $userCollectionDetails,
            'isAuthenticated' => $user !== null,
            'activeBorrowings' => $activeBorrowings,
            'isInCollection' => $isInCollection,
        ]);
    }
}
