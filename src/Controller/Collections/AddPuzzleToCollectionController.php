<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Collections;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Exceptions\CollectionAlreadyExists;
use SpeedPuzzling\Web\FormData\CollectionPuzzleActionFormData;
use SpeedPuzzling\Web\FormType\CollectionPuzzleActionFormType;
use SpeedPuzzling\Web\Message\AddPuzzleToCollection;
use SpeedPuzzling\Web\Message\CreateCollection;
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

final class AddPuzzleToCollectionController extends AbstractController
{
    public function __construct(
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private GetPlayerCollections $getPlayerCollections,
        readonly private CollectionItemRepository $collectionItemRepository,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/kolekce/{puzzleId}/pridat',
            'en' => '/en/collections/{puzzleId}/add',
            'es' => '/es/colecciones/{puzzleId}/agregar',
            'ja' => '/ja/collections/{puzzleId}/add',
            'fr' => '/fr/collections/{puzzleId}/ajouter',
            'de' => '/de/sammlungen/{puzzleId}/hinzufuegen',
        ],
        name: 'collection_add',
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

        $hasActiveMembership = $loggedPlayer->activeMembership;

        // Get available collections for this puzzle (excluding ones puzzle is already in)
        $collections = $this->getPlayerCollections->byPlayerId($loggedPlayer->playerId, true);
        $existingCollectionItems = $this->collectionItemRepository->findByPlayerAndPuzzle($loggedPlayer->playerId, $puzzleId);
        $existingCollectionIds = array_map(
            fn($item) => $item->collection?->id->toString(),
            $existingCollectionItems,
        );

        $collectionChoices = $this->buildAvailableCollectionChoices(
            $collections,
            $existingCollectionIds,
            $loggedPlayer->puzzleCollectionVisibility->value,
        );

        $hasAvailableCollections = count($collectionChoices) > 0;

        $formData = new CollectionPuzzleActionFormData();

        // Set default collection to system collection for users without membership
        if ($hasActiveMembership === false) {
            $formData->collection = Collection::SYSTEM_ID;
        }

        $form = $this->createForm(CollectionPuzzleActionFormType::class, $formData, [
            'collections' => $collectionChoices,
        ]);
        $form->handleRequest($request);

        // Handle POST - add to collection
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var CollectionPuzzleActionFormData $formData */
            $formData = $form->getData();

            $collectionId = $formData->collection;

            // Convert system collection placeholder back to null
            if ($collectionId === Collection::SYSTEM_ID) {
                $collectionId = null;
            }

            // Check if we need to create a new collection
            if ($collectionId !== null && Uuid::isValid($collectionId) === false) {
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
                        name: $collectionId,
                        description: $formData->collectionDescription,
                        visibility: $formData->collectionVisibility,
                    ));

                    // Use the new collection ID for adding the puzzle
                    $collectionId = $newCollectionId;
                } catch (HandlerFailedException $exception) {
                    $realException = $exception->getPrevious();
                    if ($realException instanceof CollectionAlreadyExists) {
                        // Use the existing collection ID instead
                        $collectionId = $realException->collectionId;
                    } else {
                        // Re-throw if it's a different exception
                        throw $exception;
                    }
                }
            }

            $this->messageBus->dispatch(new AddPuzzleToCollection(
                playerId: $loggedPlayer->playerId,
                puzzleId: $puzzleId,
                collectionId: $collectionId,
                comment: $formData->comment,
            ));

            // Check if this is a Turbo request
            if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer->playerId);

                return $this->render('collections/_stream.html.twig', [
                    'puzzle_id' => $puzzleId,
                    'puzzle_statuses' => $puzzleStatuses,
                    'message' => $this->translator->trans('collections.flash.puzzle_added'),
                ]);
            }

            // Non-Turbo request: redirect with flash message
            $this->addFlash('success', $this->translator->trans('collections.puzzle_added'));

            return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
        }

        // Handle GET - show modal/form
        $templateParams = [
            'puzzle' => $puzzle,
            'form' => $form,
            'has_active_membership' => $hasActiveMembership,
            'has_available_collections' => $hasAvailableCollections,
            'system_collection_id' => Collection::SYSTEM_ID,
            'puzzle_id' => $puzzleId,
        ];

        // Turbo Frame request - return frame content only
        if ($request->headers->get('Turbo-Frame') === 'modal-frame') {
            return $this->render('collections/modal.html.twig', $templateParams);
        }

        // Non-Turbo request: return full page for progressive enhancement
        return $this->render('collections/add.html.twig', $templateParams);
    }

    /**
     * @param array<CollectionOverview> $collections
     * @param array<null|string> $existingCollectionIds
     * @return array<string, null|string>
     */
    private function buildAvailableCollectionChoices(
        array $collections,
        array $existingCollectionIds,
        string $systemCollectionVisibility,
    ): array {
        $choices = [];

        // Add system collection if puzzle is not already in it
        if (in_array(null, $existingCollectionIds, true) === false) {
            $choices[$this->translator->trans('collections.system_name')] = Collection::SYSTEM_ID;
        }

        // Add regular collections that don't already contain the puzzle
        foreach ($collections as $collection) {
            if ($collection->collectionId !== null && !in_array($collection->collectionId, $existingCollectionIds, true)) {
                $choices[$collection->name] = $collection->collectionId;
            }
        }

        return $choices;
    }
}
