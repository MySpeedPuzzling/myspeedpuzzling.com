<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\FormData\AddPuzzleToCollectionFormData;
use SpeedPuzzling\Web\FormType\AddPuzzleToCollectionFormType;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CollectionAlreadyExists;
use SpeedPuzzling\Web\Message\AddPuzzleToCollection;
use SpeedPuzzling\Web\Message\CreateCollection;
use SpeedPuzzling\Web\Query\GetPlayerCollections;
use SpeedPuzzling\Web\Repository\CollectionItemRepository;
use SpeedPuzzling\Web\Results\CollectionOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class AddPuzzleToCollectionForm extends AbstractController
{
    use DefaultActionTrait;
    use ComponentToolsTrait;
    use ComponentWithFormTrait;

    #[LiveProp]
    public string $puzzleId = '';

    /**
     * @var null|list<CollectionOverview>
     */
    private null|array $availableCollections = null;

    public function __construct(
        readonly private GetPlayerCollections $getPlayerCollections,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private CollectionItemRepository $collectionItemRepository,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return FormInterface<AddPuzzleToCollectionFormData>
     */
    protected function instantiateForm(): FormInterface
    {
        $collections = $this->getCollectionChoices();

        return $this->createForm(AddPuzzleToCollectionFormType::class, new AddPuzzleToCollectionFormData(), [
            'collections' => $collections,
        ]);
    }

    /**
     * @return array<string, null|string>
     */
    public function getCollectionChoices(): array
    {
        $collections = $this->getAvailableCollections();
        $choices = [];

        foreach ($collections as $collection) {
            $choices[$collection->name] = $collection->collectionId;
        }

        return $choices;
    }

    #[LiveAction]
    public function save(): void
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            $this->dispatchBrowserEvent('toast:show', [
                'message' => 'You must be logged in to add puzzles to collections.',
                'type' => 'error',
            ]);

            return;
        }

        $this->submitForm();

        /** @var AddPuzzleToCollectionFormData $formData */
        $formData = $this->getForm()->getData();

        $collectionId = $formData->collection;

        // Convert system collection placeholder back to null
        if ($collectionId === '__system_collection__') {
            $collectionId = null;
        }

        // Check if we need to create a new collection
        if ($collectionId !== null && Uuid::isValid($collectionId) === false) {
            // Generate new UUID for the collection
            $newCollectionId = Uuid::uuid7()->toString();

            try {
                // Create the collection first
                $this->messageBus->dispatch(new CreateCollection(
                    collectionId: $newCollectionId,
                    playerId: $player->playerId,
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
            playerId: $player->playerId,
            puzzleId: $this->puzzleId,
            collectionId: $collectionId,
            comment: $formData->comment,
        ));

        // Clear cached collections to refresh available options
        $this->availableCollections = null;

        // Show success toast and emit events to close modal and refresh parent components
        $this->dispatchBrowserEvent('toast:show', [
            'message' => 'Puzzle successfully added to collection.',
            'type' => 'success',
        ]);

        $this->dispatchBrowserEvent('modal:close');

        // Emit event to refresh parent component
        $this->emit('puzzle:addedToCollection', [
            'puzzleId' => $this->puzzleId,
        ]);

        $this->resetForm();
    }

    #[LiveListener('puzzle:removedFromCollection')]
    public function onCollectionChanged(): void
    {
        // Clear cached collections to force refresh
        $this->availableCollections = null;
    }

    /**
     * @return list<CollectionOverview>
     */
    private function getAvailableCollections(): array
    {
        if ($this->availableCollections !== null) {
            return $this->availableCollections;
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return [];
        }

        $collections = $this->getPlayerCollections->byPlayerId($player->playerId, true);
        $existingCollectionItems = $this->collectionItemRepository->findByPlayerAndPuzzle($player->playerId, $this->puzzleId);

        // Create set of existing collection IDs (including null for system collection)
        $existingCollectionIds = [];

        foreach ($existingCollectionItems as $item) {
            $existingCollectionIds[] = $item->collection?->id->toString();
        }

        // Filter out collections that already contain this puzzle
        $availableCollections = [];

        // Only add system collection if not already containing the puzzle
        if (in_array(null, $existingCollectionIds, true) === false) {
            $availableCollections[] = new CollectionOverview(
                playerId: $player->playerId,
                collectionId: '__system_collection__',
                name: $this->translator->trans('collections.system_name'),
                description: null,
                visibility: $player->puzzleCollectionVisibility,
            );
        }

        // Add regular collections that don't already contain the puzzle
        foreach ($collections as $collection) {
            if ($collection->collectionId !== null && !in_array($collection->collectionId, $existingCollectionIds, true)) {
                $availableCollections[] = $collection;
            }
        }

        $this->availableCollections = $availableCollections;

        return $this->availableCollections;
    }
}
