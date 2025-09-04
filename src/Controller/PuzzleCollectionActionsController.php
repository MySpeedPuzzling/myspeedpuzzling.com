<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetCollectionPuzzles;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
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

        if ($user !== null) {
            $loggedUserProfile = $this->retrieveLoggedUserProfile->getProfile();
            if ($loggedUserProfile !== null) {
                // Get which collections this puzzle is already in for this user
                $userCollections = $this->getCollectionPuzzles->getPuzzleCollections(
                    $loggedUserProfile->playerId,
                    $puzzleId
                );
            }
        }

        return $this->render('components/_puzzle_collection_actions.html.twig', [
            'puzzle' => $puzzle,
            'userCollections' => $userCollections,
            'isAuthenticated' => $user !== null,
        ]);
    }
}
