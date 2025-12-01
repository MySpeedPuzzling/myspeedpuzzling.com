<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\FormData\CollectionFormData;
use SpeedPuzzling\Web\FormType\CollectionFormType;
use SpeedPuzzling\Web\Message\EditCollection;
use SpeedPuzzling\Web\Repository\CollectionRepository;
use SpeedPuzzling\Web\Results\CollectionOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EditCollectionController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private CollectionRepository $collectionRepository,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws CollectionNotFound
     */
    #[Route(
        path: [
            'cs' => '/upravit-kolekci/{collectionId}',
            'en' => '/en/edit-collection/{collectionId}',
            'es' => '/es/editar-coleccion/{collectionId}',
            'ja' => '/ja/コレクションを編集/{collectionId}',
            'fr' => '/fr/modifier-collection/{collectionId}',
            'de' => '/de/sammlung-bearbeiten/{collectionId}',
        ],
        name: 'edit_collection',
    )]
    public function __invoke(Request $request, #[CurrentUser] User $user, string $collectionId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }


        $collection = $this->collectionRepository->get($collectionId);

        if ($collection->player->id->toString() !== $player->playerId) {
            throw new CollectionNotFound();
        }

        $collectionOverview = new CollectionOverview(
            playerId: $collection->player->id->toString(),
            collectionId: $collection->id->toString(),
            name: $collection->name,
            description: $collection->description,
            visibility: $collection->visibility,
        );

        $formData = CollectionFormData::fromCollectionOverview($collectionOverview);

        $form = $this->createForm(CollectionFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->messageBus->dispatch(
                new EditCollection(
                    collectionId: $collectionId,
                    playerId: $player->playerId,
                    name: $formData->name ?? '',
                    description: $formData->description,
                    visibility: $formData->visibility ?? CollectionVisibility::Private,
                ),
            );

            $this->addFlash('success', $this->translator->trans('collections.updated'));

            return $this->redirectToRoute('puzzle_library', ['playerId' => $player->playerId]);
        }

        return $this->render('collections/edit.html.twig', [
            'form' => $form,
            'collection' => $collectionOverview,
            'player' => $player,
            'cancelUrl' => $this->generateUrl('puzzle_library', ['playerId' => $player->playerId]),
        ]);
    }
}
