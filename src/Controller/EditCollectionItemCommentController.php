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
use SpeedPuzzling\Web\Services\CustomFields\SubmittedValuesChange;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CustomValues;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
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
 *
 * The comment was here first and keeps working whatever happens to the fields: they are read failing open (the form
 * of a player without fields), checked before anything is written (a wrong value = 422, nothing saved), and saved
 * after the comment by a step that can not lose it.
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
        readonly private LoggerInterface $logger,
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
        // bound to the signed-in player's id. Failing open: the comment form works without them
        $fields = [];
        $stored = new CustomValues();

        try {
            $fields = $this->getCustomFields->usableOnCollectionItems($player->playerId);

            if ($fields !== []) {
                $stored = $this->getCollectionItemCustomValues->forViewer([$collectionItemId], $player->playerId)[$collectionItemId] ?? $stored;
            }
        } catch (\Throwable $exception) {
            // Reading carries no value of anybody (the statements' parameters are ids) - logged as it is
            $this->logger->warning('Custom fields could not be loaded for the collection item\'s edit form - it is shown without them', [
                'exception' => $exception,
            ]);

            $fields = [];
        }

        $storedInputs = [];

        foreach ($fields as $field) {
            $storedInputs[$field->fieldId] = CustomValues::toInput($stored->of($field->fieldId));
        }

        $inputs = $storedInputs;
        $problems = [];

        $formData = EditCollectionItemCommentFormData::fromCollectionItem($collectionItem);

        $form = $this->createForm(EditCollectionItemCommentFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            // Only the fields the form shows; whatever the answer is, what was typed stays in the inputs
            $inputs = array_intersect_key(SubmittedCustomValues::of($request), $inputs) + $inputs;
        }

        if ($form->isSubmitted() && $form->isValid()) {
            // Checked before anything is written: a value that can not be stored refuses the save as a whole
            $problems = SubmittedValuesChange::problems($stored, $fields, $inputs);
        }

        if ($form->isSubmitted() && $form->isValid() && $problems === []) {
            // The same fields in both, in whatever order they were sent
            $fieldsChanged = array_diff_assoc($inputs, $storedInputs) !== [];

            $this->messageBus->dispatch(
                new EditCollectionItemComment(
                    collectionItemId: $collectionItemId,
                    playerId: $player->playerId,
                    comment: $formData->comment,
                ),
            );

            // The comment's own words as long as only the comment was in play
            $this->addFlash('success', $this->translator->trans($fieldsChanged ? 'my_fields.collection_item.saved' : 'collections.flash.comment_updated'));

            // Redirect back to collection detail or system collection
            $answer = $collectionItem->collection !== null
                ? $this->redirectToRoute('collection_detail', ['collectionId' => $collectionItem->collection->id->toString()])
                : $this->redirectToRoute('system_collection_detail', ['playerId' => $player->playerId]);

            // The fields last, after the comment is saved and its answer built: a write that failed at the database
            // closes Doctrine's entity manager, and nothing of the comment may depend on that
            if ($fieldsChanged) {
                $this->saveFields($request, $collectionItemId, $player->playerId, $inputs);
            }

            return $answer;
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

    /**
     * Never throws - the comment is saved and answered. A refusal here is rare (the values were checked a moment ago:
     * a field archived in another tab meanwhile); anything else is logged without a value in it.
     *
     * @param array<string, string> $inputs
     */
    private function saveFields(Request $request, string $collectionItemId, string $playerId, array $inputs): void
    {
        try {
            $this->messageBus->dispatch(new SetCollectionItemCustomValues($collectionItemId, $playerId, $inputs));
        } catch (\Throwable $exception) {
            try {
                $refusal = $exception instanceof HandlerFailedException ? $exception->getPrevious() : null;

                if (!$refusal instanceof InvalidCustomValues) {
                    $this->logger->warning('Custom fields of a collection item could not be saved - its comment was', [
                        'collectionItemId' => $collectionItemId,
                        // Never the exception itself: a driver's message can quote the values (CustomValuesWriteFailure)
                        'exception' => CustomValuesWriteFailure::withoutValues($exception),
                    ]);
                }

                // The success flash would say more than what happened
                $session = $request->hasSession() ? $request->getSession() : null;

                if ($session instanceof FlashBagAwareSessionInterface) {
                    $session->getFlashBag()->get('success');
                    $session->getFlashBag()->add('warning', $this->translator->trans('my_fields.collection_item.not_saved'));
                }
            } catch (\Throwable) {
                // The comment is saved - nothing about the player's own fields may get in its way
            }
        }
    }
}
