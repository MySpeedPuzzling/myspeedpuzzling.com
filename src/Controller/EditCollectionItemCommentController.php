<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Exceptions\CollectionItemNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\FormData\EditCollectionItemCommentFormData;
use SpeedPuzzling\Web\FormType\EditCollectionItemCommentFormType;
use SpeedPuzzling\Web\Message\EditCollectionItemComment;
use SpeedPuzzling\Web\Repository\CollectionItemRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EditCollectionItemCommentController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private CollectionItemRepository $collectionItemRepository,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws CollectionItemNotFound
     */
    #[Route(
        path: [
            'cs' => '/upravit-komentar-kolekce/{collectionItemId}',
            'en' => '/en/edit-collection-item-comment/{collectionItemId}',
            'es' => '/es/editar-comentario-coleccion/{collectionItemId}',
            'ja' => '/ja/コレクションコメント編集/{collectionItemId}',
            'fr' => '/fr/modifier-commentaire-collection/{collectionItemId}',
            'de' => '/de/sammlungs-kommentar-bearbeiten/{collectionItemId}',
        ],
        name: 'edit_collection_item_comment',
    )]
    public function __invoke(Request $request, #[CurrentUser] User $user, string $collectionItemId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }

        $collectionItem = $this->collectionItemRepository->get($collectionItemId);

        if ($collectionItem->player->id->toString() !== $player->playerId) {
            throw new CollectionItemNotFound();
        }

        $formData = EditCollectionItemCommentFormData::fromCollectionItem($collectionItem);

        $form = $this->createForm(EditCollectionItemCommentFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->messageBus->dispatch(
                new EditCollectionItemComment(
                    collectionItemId: $collectionItemId,
                    playerId: $player->playerId,
                    comment: $formData->comment,
                ),
            );

            $this->addFlash('success', $this->translator->trans('collections.flash.comment_updated'));

            // Redirect back to collection detail or system collection
            if ($collectionItem->collection !== null) {
                return $this->redirectToRoute('collection_detail', [
                    'collectionId' => $collectionItem->collection->id->toString(),
                ]);
            }

            return $this->redirectToRoute('system_collection_detail', [
                'playerId' => $player->playerId,
            ]);
        }

        // Determine cancel URL based on collection type
        if ($collectionItem->collection !== null) {
            $cancelUrl = $this->generateUrl('collection_detail', [
                'collectionId' => $collectionItem->collection->id->toString(),
            ]);
        } else {
            $cancelUrl = $this->generateUrl('system_collection_detail', [
                'playerId' => $player->playerId,
            ]);
        }

        return $this->render('collections/edit-item-comment.html.twig', [
            'form' => $form,
            'collectionItem' => $collectionItem,
            'player' => $player,
            'cancelUrl' => $cancelUrl,
        ]);
    }
}
