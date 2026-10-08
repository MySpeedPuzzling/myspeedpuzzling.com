<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Collections;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Exceptions\CollectionAlreadyExists;
use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\FormData\CollectionPuzzleActionFormData;
use SpeedPuzzling\Web\FormType\CollectionPuzzleActionFormType;
use SpeedPuzzling\Web\Message\CopyPuzzlesToCollection;
use SpeedPuzzling\Web\Message\CreateCollection;
use SpeedPuzzling\Web\Message\MovePuzzlesToCollection;
use SpeedPuzzling\Web\Query\GetPlayerCollections;
use SpeedPuzzling\Web\Services\CollectionSelectionResponder;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CollectionSelection;
use SpeedPuzzling\Web\Value\SelectedPuzzlesOutcome;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Move to…" / "Copy to…" for the puzzles selected on a collection page (docs/features/collections/bulk-actions.md).
 * The selection bar posts the ids into the modal frame, which answers the target picker carrying them on; the
 * picker's submit moves or copies everything in one message and answers the page's Turbo Streams.
 */
final class MoveSelectedPuzzlesController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPlayerCollections $getPlayerCollections,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private CollectionSelectionResponder $responder,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/kolekce/{collectionId}/vybrane/{mode}',
            'en' => '/en/collections/{collectionId}/selected/{mode}',
            'es' => '/es/colecciones/{collectionId}/seleccionados/{mode}',
            'ja' => '/ja/collections/{collectionId}/selected/{mode}',
            'fr' => '/fr/collections/{collectionId}/selection/{mode}',
            'de' => '/de/sammlungen/{collectionId}/auswahl/{mode}',
        ],
        name: 'collection_selected_move',
        requirements: ['mode' => 'move|copy'],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $collectionId, string $mode): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        if ($player->activeMembership === false) {
            throw $this->createAccessDeniedException();
        }

        $collections = $this->getPlayerCollections->byPlayerId($player->playerId, true);
        $selection = CollectionSelection::fromRequest(
            $request,
            $collectionId,
            $collections,
            $this->translator->trans('collections.system_name'),
        );

        if ($selection === null) {
            throw new CollectionNotFound();
        }

        // Every own collection except the one the page shows
        $choices = [];
        if ($selection->collectionId !== null) {
            $choices[$this->translator->trans('collections.system_name')] = Collection::SYSTEM_ID;
        }
        foreach ($collections as $collection) {
            if ($collection->collectionId !== $selection->collectionId) {
                $choices[$collection->name] = $collection->collectionId;
            }
        }

        $form = $this->createForm(CollectionPuzzleActionFormType::class, new CollectionPuzzleActionFormData(), [
            'collections' => $choices,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $selection->puzzleIds !== []) {
            if ($this->isCsrfTokenValid(CollectionSelection::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
                throw $this->createAccessDeniedException();
            }

            /** @var CollectionPuzzleActionFormData $formData */
            $formData = $form->getData();
            [$targetCollectionId, $targetName] = $this->resolveTarget($formData, $player->playerId, $choices);

            $message = $mode === 'copy'
                ? new CopyPuzzlesToCollection($player->playerId, $selection->puzzleIds, $selection->collectionId, $targetCollectionId)
                : new MovePuzzlesToCollection($player->playerId, $selection->puzzleIds, $selection->collectionId, $targetCollectionId);

            $outcome = $this->messageBus->dispatch($message)->last(HandledStamp::class)?->getResult();
            assert($outcome instanceof SelectedPuzzlesOutcome);

            $toast = $this->translator->trans('collection_selection.done.' . ($mode === 'copy' ? 'copied' : 'moved'), [
                '%count%' => $outcome->changed,
                '%collection%' => $targetName,
            ]);

            return $this->responder->respond(
                request: $request,
                selection: $selection,
                playerId: $player->playerId,
                outcome: $outcome,
                toast: $toast,
                removeCards: $mode === 'move',
                openUrl: $targetCollectionId === null
                    ? $this->generateUrl('system_collection_detail', ['playerId' => $player->playerId])
                    : $this->generateUrl('collection_detail', ['collectionId' => $targetCollectionId]),
            );
        }

        return $this->render('collections/selected_move_modal.html.twig', [
            'form' => $form,
            'mode' => $mode,
            'selection' => $selection,
            'system_collection_id' => Collection::SYSTEM_ID,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * The picked collection, or a new one from a typed name (an existing name = that collection)
     *
     * @param array<string, null|string> $choices
     * @return array{null|string, string}
     */
    private function resolveTarget(CollectionPuzzleActionFormData $formData, string $playerId, array $choices): array
    {
        $target = $formData->collection;

        if ($target === null || $target === Collection::SYSTEM_ID) {
            return [null, $this->translator->trans('collections.system_name')];
        }

        if (Uuid::isValid($target)) {
            $name = array_search($target, $choices, true);

            if ($name === false) {
                throw new CollectionNotFound();
            }

            return [$target, (string) $name];
        }

        $newCollectionId = Uuid::uuid7()->toString();

        try {
            $this->messageBus->dispatch(new CreateCollection(
                collectionId: $newCollectionId,
                playerId: $playerId,
                name: $target,
                description: $formData->collectionDescription,
                visibility: $formData->collectionVisibility,
            ));
        } catch (HandlerFailedException $exception) {
            $previous = $exception->getPrevious();

            if ($previous instanceof CollectionAlreadyExists) {
                return [$previous->collectionId, $target];
            }

            throw $exception;
        }

        return [$newCollectionId, $target];
    }
}
