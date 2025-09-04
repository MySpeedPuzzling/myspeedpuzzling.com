<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\FormData\PuzzleCollectionFormData;
use SpeedPuzzling\Web\FormType\PuzzleCollectionFormType;
use SpeedPuzzling\Web\Message\CreatePuzzleCollection;
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
final class CreateCollectionController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/en/new-collection',
        name: 'create_collection',
    )]
    public function __invoke(Request $request, #[CurrentUser] UserInterface $user): Response
    {
        $loggedUserProfile = $this->retrieveLoggedUserProfile->getProfile();

        if ($loggedUserProfile === null) {
            throw $this->createAccessDeniedException();
        }

        $formData = new PuzzleCollectionFormData();
        $form = $this->createForm(PuzzleCollectionFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $collectionId = Uuid::uuid7();

            $this->messageBus->dispatch(new CreatePuzzleCollection(
                collectionId: $collectionId,
                playerId: $loggedUserProfile->playerId,
                name: $formData->name ?? '',
                description: $formData->description,
                isPublic: $formData->isPublic,
            ));

            $this->addFlash('success', 'Collection created successfully');

            return $this->redirectToRoute('collection_detail', [
                'collectionId' => $collectionId->toString(),
            ]);
        }

        return $this->render('create_collection.html.twig', [
            'form' => $form,
        ]);
    }
}
