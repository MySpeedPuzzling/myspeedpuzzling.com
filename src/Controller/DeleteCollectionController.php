<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PuzzleCollectionNotFound;
use SpeedPuzzling\Web\Message\DeletePuzzleCollection;
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
final class DeleteCollectionController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PuzzleCollectionRepository $collectionRepository,
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/en/collection/{collectionId}/delete',
        name: 'delete_collection',
        methods: ['POST'],
    )]
    public function __invoke(string $collectionId, Request $request, #[CurrentUser] UserInterface $user): Response
    {
        $loggedUserProfile = $this->retrieveLoggedUserProfile->getProfile();

        if ($loggedUserProfile === null) {
            throw $this->createAccessDeniedException();
        }

        try {
            $collection = $this->collectionRepository->get($collectionId);
        } catch (PuzzleCollectionNotFound) {
            throw $this->createNotFoundException();
        }

        // Check ownership
        if ($collection->player->id->toString() !== $loggedUserProfile->playerId) {
            throw $this->createAccessDeniedException();
        }

        // Cannot delete system collections
        if (!$collection->canBeDeleted()) {
            throw $this->createAccessDeniedException();
        }

        // Check CSRF token
        $submittedToken = $request->request->getString('_token');
        if (!$this->isCsrfTokenValid('delete-collection', $submittedToken)) {
            throw $this->createAccessDeniedException();
        }

        $this->messageBus->dispatch(new DeletePuzzleCollection(
            collectionId: $collectionId,
        ));

        $this->addFlash('success', 'Collection deleted successfully');

        return $this->redirectToRoute('player_collections', [
            'playerId' => $loggedUserProfile->playerId,
        ]);
    }
}
