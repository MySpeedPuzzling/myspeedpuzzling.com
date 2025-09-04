<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PuzzleCollectionNotFound;
use SpeedPuzzling\Web\Message\RemovePuzzleFromCollection;
use SpeedPuzzling\Web\Repository\PuzzleCollectionRepository;
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
final class RemovePuzzleFromCollectionController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PuzzleCollectionRepository $collectionRepository,
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/en/collection/{collectionId}/remove-puzzle',
        name: 'remove_puzzle_from_collection',
        methods: ['POST'],
    )]
    public function __invoke(string $collectionId, Request $request, #[CurrentUser] UserInterface $user): Response
    {
        $loggedUserProfile = $this->retrieveLoggedUserProfile->getProfile();
        $puzzleId = $request->request->getString('puzzle_id', '');

        if ($loggedUserProfile === null) {
            throw $this->createAccessDeniedException();
        }

        if ($puzzleId === '') {
            throw $this->createNotFoundException();
        }

        // Check CSRF token
        $submittedToken = $request->request->getString('_token');
        if (!$this->isCsrfTokenValid('remove-from-collection', $submittedToken)) {
            throw $this->createAccessDeniedException();
        }

        try {
            $collection = $this->collectionRepository->get($collectionId);

            // Check ownership
            if ($collection->player->id->toString() !== $loggedUserProfile->playerId) {
                throw $this->createAccessDeniedException();
            }

            $this->messageBus->dispatch(new RemovePuzzleFromCollection(
                puzzleId: $puzzleId,
                collectionId: $collectionId,
                playerId: $loggedUserProfile->playerId,
            ));

            $this->addFlash('success', 'Puzzle removed from collection');
        } catch (PuzzleCollectionNotFound) {
            throw $this->createNotFoundException();
        }

        return $this->redirectToRoute('collection_detail', [
            'collectionId' => $collectionId,
        ]);
    }
}
