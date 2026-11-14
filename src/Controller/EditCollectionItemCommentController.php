<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Component\Security\Core\User\UserInterface;
use SpeedPuzzling\Web\Exceptions\CollectionItemNotFound;
use SpeedPuzzling\Web\Exceptions\InvalidCustomValues;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\FormData\EditCollectionItemCommentFormData;
use SpeedPuzzling\Web\FormType\EditCollectionItemCommentFormType;
use SpeedPuzzling\Web\Message\EditCollectionItemComment;
use SpeedPuzzling\Web\Message\SetCollectionItemCustomValues;
use SpeedPuzzling\Web\Query\GetCollectionItemCustomValues;
use SpeedPuzzling\Web\Query\GetCustomFields;
use SpeedPuzzling\Web\Repository\CollectionItemRepository;
use SpeedPuzzling\Web\Results\CustomFieldsForm;
use SpeedPuzzling\Web\Services\CustomFields\CustomValuesWriteFailure;
use SpeedPuzzling\Web\Services\CustomFields\SubmittedCustomValues;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CustomValues;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The owner's edit form of one puzzle in their collection: the comment, and the owner's own fields for collection
 * puzzles (docs/features/custom-fields.md "Collection items") - the only page that shows those values. An item has
 * one owner, so the fields sit in this form; they are plain inputs beside the Symfony form (`my_fields[<field id>]`),
 * read and written for the signed-in owner only.
 */
final class EditCollectionItemCommentController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private CollectionItemRepository $collectionItemRepository,
        readonly private GetCustomFields $getCustomFields,
        readonly private GetCollectionItemCustomValues $getCollectionItemCustomValues,
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
    public function __invoke(Request $request, #[CurrentUser] UserInterface $user, string $collectionItemId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }

        $collectionItem = $this->collectionItemRepository->get($collectionItemId);

        if ($collectionItem->player->id->toString() !== $player->playerId) {
            throw new CollectionItemNotFound();
        }

        $collectionItemId = $collectionItem->id->toString();

        // The signed-in owner's fields for collection puzzles and what this item holds in them - both statements are
        // bound to the signed-in player's id
        $fields = $this->getCustomFields->usableOnCollectionItems($player->playerId);
        $stored = $fields === []
            ? new CustomValues()
            : $this->getCollectionItemCustomValues->forViewer([$collectionItemId], $player->playerId)[$collectionItemId] ?? new CustomValues();

        $inputs = [];

        foreach ($fields as $field) {
            $inputs[$field->fieldId] = CustomValues::toInput($stored->of($field->fieldId));
        }

        $problems = [];

        $formData = EditCollectionItemCommentFormData::fromCollectionItem($collectionItem);

        $form = $this->createForm(EditCollectionItemCommentFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            // Only the fields the form shows; whatever the answer is, what was typed stays in the inputs
            $inputs = array_intersect_key(SubmittedCustomValues::of($request), $inputs) + $inputs;
        }

        if ($form->isSubmitted() && $form->isValid()) {
            // The fields first: they are the part that can be refused, and nothing is saved then
            if ($fields !== []) {
                try {
                    $this->messageBus->dispatch(new SetCollectionItemCustomValues($collectionItemId, $player->playerId, $inputs));
                } catch (HandlerFailedException $exception) {
                    $refusal = $exception->getPrevious();

                    if (!$refusal instanceof InvalidCustomValues) {
                        // Never the exception itself: a driver's message can quote the values
                        throw CustomValuesWriteFailure::withoutValues($exception);
                    }

                    $problems = $refusal->problems;
                }
            }

            if ($problems === []) {
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

        $response = $this->render('collections/edit-item-comment.html.twig', [
            'form' => $form,
            'collectionItem' => $collectionItem,
            'player' => $player,
            'cancelUrl' => $cancelUrl,
            'my_fields' => new CustomFieldsForm($fields, $inputs, $problems),
        ]);

        // A refused save is never a 200: Turbo Drive drops a 200 answer to a form submission
        if ($problems !== []) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The owner's own values are in the page - never for a shared cache
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
