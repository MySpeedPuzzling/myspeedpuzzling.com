<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Collections;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Exceptions\CollectionAlreadyExists;
use SpeedPuzzling\Web\FormData\CollectionPuzzleActionFormData;
use SpeedPuzzling\Web\FormType\CollectionPuzzleActionFormType;
use SpeedPuzzling\Web\Message\CreateCollection;
use SpeedPuzzling\Web\Query\GetCollectionItems;
use SpeedPuzzling\Web\Message\MovePuzzleToCollection;
use SpeedPuzzling\Web\Query\GetPlayerCollections;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Repository\CollectionItemRepository;
use SpeedPuzzling\Web\Results\CollectionOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

final class MovePuzzleToCollectionController extends AbstractController
{
    public function __construct(
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private GetPlayerCollections $getPlayerCollections,
        readonly private CollectionItemRepository $collectionItemRepository,
        readonly private GetCollectionItems $getCollectionItems,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/kolekce/{puzzleId}/presunout',
            'en' => '/en/collections/{puzzleId}/move',
            'es' => '/es/colecciones/{puzzleId}/mover',
            'ja' => '/ja/collections/{puzzleId}/move',
            'fr' => '/fr/collections/{puzzleId}/deplacer',
            'de' => '/de/sammlungen/{puzzleId}/verschieben',
        ],
        name: 'collection_move',
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        Request $request,
        string $puzzleId,
    ): Response {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $puzzle = $this->getPuzzleOverview->byId($puzzleId);

        // Get source collection from query parameter
        $sourceCollectionId = $request->query->get('sourceCollectionId');
        if ($sourceCollectionId === Collection::SYSTEM_ID) {
            $sourceCollectionId = null;
        }

        $hasActiveMembership = $loggedPlayer->activeMembership;

        // Get existing comment from the source collection item
        $existingComment = null;
        $collectionItems = $this->collectionItemRepository->findByPlayerAndPuzzle($loggedPlayer->playerId, $puzzleId);
        foreach ($collectionItems as $item) {
            $itemCollectionId = $item->collection?->id->toString();
            if ($itemCollectionId === $sourceCollectionId) {
                $existingComment = $item->comment;
                break;
            }
        }

        // Get available target collections (excluding source and ones puzzle is already in)
        $collections = $this->getPlayerCollections->byPlayerId($loggedPlayer->playerId, true);
        $existingCollectionIds = array_map(
            fn($item) => $item->collection?->id->toString(),
            $collectionItems,
        );

        $collectionChoices = $this->buildAvailableTargetCollectionChoices(
            $collections,
            $existingCollectionIds,
            $sourceCollectionId,
            $loggedPlayer->puzzleCollectionVisibility->value,
        );

        $hasAvailableCollections = count($collectionChoices) > 0;

        $formData = new CollectionPuzzleActionFormData();

        // Pre-fill comment from existing item
        if ($existingComment !== null) {
            $formData->comment = $existingComment;
        }

        // Set default collection to system collection for users without membership
        if ($hasActiveMembership === false) {
            $formData->collection = Collection::SYSTEM_ID;
        }

        $form = $this->createForm(CollectionPuzzleActionFormType::class, $formData, [
            'collections' => $collectionChoices,
        ]);
        $form->handleRequest($request);

        // Handle POST - move puzzle to collection
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var CollectionPuzzleActionFormData $formData */
            $formData = $form->getData();

            $targetCollectionId = $formData->collection;

            // Convert system collection placeholder back to null
            if ($targetCollectionId === Collection::SYSTEM_ID) {
                $targetCollectionId = null;
            }

            // Check if we need to create a new collection
            if ($targetCollectionId !== null && Uuid::isValid($targetCollectionId) === false) {
                // Check if user has active membership to create collections
                if ($hasActiveMembership === false) {
                    $this->addFlash('warning', $this->translator->trans('collections.membership_required.toast'));

                    return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
                }

                // Generate new UUID for the collection
                $newCollectionId = Uuid::uuid7()->toString();

                try {
                    // Create the collection first
                    $this->messageBus->dispatch(new CreateCollection(
                        collectionId: $newCollectionId,
                        playerId: $loggedPlayer->playerId,
                        name: $targetCollectionId,
                        description: $formData->collectionDescription,
                        visibility: $formData->collectionVisibility,
                    ));

                    // Use the new collection ID for moving the puzzle
                    $targetCollectionId = $newCollectionId;
                } catch (HandlerFailedException $exception) {
                    $realException = $exception->getPrevious();
                    if ($realException instanceof CollectionAlreadyExists) {
                        // Use the existing collection ID instead
                        $targetCollectionId = $realException->collectionId;
                    } else {
                        // Re-throw if it's a different exception
                        throw $exception;
                    }
                }
            }

            $this->messageBus->dispatch(new MovePuzzleToCollection(
                playerId: $loggedPlayer->playerId,
                puzzleId: $puzzleId,
                sourceCollectionId: $sourceCollectionId,
                targetCollectionId: $targetCollectionId,
                comment: $formData->comment,
            ));

            // Check if this is a Turbo request
            if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer->playerId);
                $context = $request->query->getString('context', 'detail');

                // If coming from collection list, use the list stream template
                if ($context === 'list' || $context === 'collection-detail') {
                    $currentCollectionId = $request->query->get('sourceCollectionId') ?? Collection::SYSTEM_ID;
                    $currentCollectionIdForQuery = $currentCollectionId === Collection::SYSTEM_ID ? null : $currentCollectionId;

                    $remainingCount = $this->getCollectionItems->countByCollectionAndPlayer(
                        $currentCollectionIdForQuery,
                        $loggedPlayer->playerId,
                    );

                    return $this->render('collections/_remove_from_list_stream.html.twig', [
                        'puzzle_id' => $puzzleId,
                        'puzzle_statuses' => $puzzleStatuses,
                        'removed_from_collection_id' => $currentCollectionId,
                        'current_collection_id' => $currentCollectionId,
                        'remaining_count' => $remainingCount,
                        'source_collection_id' => $currentCollectionId,
                        'context' => 'list',
                        'message' => $this->translator->trans('collections.flash.puzzle_moved'),
                    ]);
                }

                return $this->render('collections/_stream.html.twig', [
                    'puzzle_id' => $puzzleId,
                    'puzzle_statuses' => $puzzleStatuses,
                    'message' => $this->translator->trans('collections.flash.puzzle_moved'),
                ]);
            }

            // Non-Turbo request: redirect with flash message
            $this->addFlash('success', $this->translator->trans('collections.flash.puzzle_moved'));

            return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
        }

        // Determine source collection name for display
        $sourceCollectionName = $this->translator->trans('collections.system_name');
        if ($sourceCollectionId !== null) {
            foreach ($collections as $collection) {
                if ($collection->collectionId === $sourceCollectionId) {
                    $sourceCollectionName = $collection->name;
                    break;
                }
            }
        }

        // Handle GET - show modal/form
        $templateParams = [
            'puzzle' => $puzzle,
            'form' => $form,
            'has_active_membership' => $hasActiveMembership,
            'has_available_collections' => $hasAvailableCollections,
            'system_collection_id' => Collection::SYSTEM_ID,
            'puzzle_id' => $puzzleId,
            'source_collection_id' => $request->query->get('sourceCollectionId') ?? Collection::SYSTEM_ID,
            'source_collection_name' => $sourceCollectionName,
            'context' => $request->query->getString('context', ''),
        ];

        // Turbo Frame request - return frame content only
        if ($request->headers->get('Turbo-Frame') === 'modal-frame') {
            return $this->render('collections/move_modal.html.twig', $templateParams);
        }

        // Non-Turbo request: return full page for progressive enhancement
        return $this->render('collections/move.html.twig', $templateParams);
    }

    /**
     * @param array<CollectionOverview> $collections
     * @param array<null|string> $existingCollectionIds
     * @return array<string, null|string>
     */
    private function buildAvailableTargetCollectionChoices(
        array $collections,
        array $existingCollectionIds,
        null|string $sourceCollectionId,
        string $systemCollectionVisibility,
    ): array {
        $choices = [];

        // Add system collection if:
        // - Puzzle is not already in it
        // - It's not the source collection
        if (
            in_array(null, $existingCollectionIds, true) === false
            && $sourceCollectionId !== null
        ) {
            $choices[$this->translator->trans('collections.system_name')] = Collection::SYSTEM_ID;
        }

        // Add regular collections that:
        // - Don't already contain the puzzle
        // - Are not the source collection
        foreach ($collections as $collection) {
            if (
                $collection->collectionId !== null
                && !in_array($collection->collectionId, $existingCollectionIds, true)
                && $collection->collectionId !== $sourceCollectionId
            ) {
                $choices[$collection->name] = $collection->collectionId;
            }
        }

        return $choices;
    }
}
