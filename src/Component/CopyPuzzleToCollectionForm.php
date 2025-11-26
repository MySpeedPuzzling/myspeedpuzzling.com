<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\FormData\CollectionPuzzleActionFormData;
use SpeedPuzzling\Web\FormType\CollectionPuzzleActionFormType;
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
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class CopyPuzzleToCollectionForm extends AbstractController
{
    use DefaultActionTrait;
    use ComponentToolsTrait;
    use ComponentWithFormTrait;

    #[LiveProp]
    public string $puzzleId = '';

    #[LiveProp]
    public null|string $sourceCollectionId = null;

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
     * @return FormInterface<CollectionPuzzleActionFormData>
     */
    protected function instantiateForm(): FormInterface
    {
        $collections = $this->getCollectionChoices();
        $formData = new CollectionPuzzleActionFormData();

        // Set default collection to system collection for users without membership
        if ($this->hasActiveMembership() === false) {
            $formData->collection = Collection::SYSTEM_ID;
        }

        return $this->createForm(CollectionPuzzleActionFormType::class, $formData, [
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

    public function hasActiveMembership(): bool
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return false;
        }

        return $player->activeMembership;
    }

    public function getSystemCollectionId(): string
    {
        return Collection::SYSTEM_ID;
    }

    public function hasAvailableCollections(): bool
    {
        // For non-members, only check if system collection is available
        if ($this->hasActiveMembership() === false) {
            $player = $this->retrieveLoggedUserProfile->getProfile();

            if ($player === null) {
                return false;
            }

            $existingCollectionItems = $this->collectionItemRepository->findByPlayerAndPuzzle($player->playerId, $this->puzzleId);

            // Check if puzzle is in system collection (collection is null)
            foreach ($existingCollectionItems as $item) {
                if ($item->collection === null) {
                    // Puzzle is already in system collection - can't copy there
                    return false;
                }
            }

            // System collection is available as target
            return true;
        }

        // For members, check if there are any available collections
        return count($this->getCollectionChoices()) > 0;
    }

    #[LiveAction]
    public function save(): void
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            $this->dispatchBrowserEvent('toast:show', [
                'message' => $this->translator->trans('collections.flash.login_required_copy'),
                'type' => 'error',
            ]);

            return;
        }

        $this->submitForm();

        /** @var CollectionPuzzleActionFormData $formData */
        $formData = $this->getForm()->getData();

        $collectionId = $formData->collection;

        // Convert system collection placeholder back to null
        if ($collectionId === Collection::SYSTEM_ID) {
            $collectionId = null;
        }

        // Check if we need to create a new collection
        if ($collectionId !== null && Uuid::isValid($collectionId) === false) {
            // Check if user has active membership to create collections
            if ($this->hasActiveMembership() === false) {
                $this->dispatchBrowserEvent('toast:show', [
                    'message' => $this->translator->trans('collections.membership_required.toast'),
                    'type' => 'error',
                ]);

                return;
            }

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
            'message' => $this->translator->trans('collections.puzzle_copied'),
            'type' => 'success',
        ]);

        $this->dispatchBrowserEvent('modal:close');

        // Emit event to refresh parent component
        $this->emit('puzzle:copiedToCollection', [
            'puzzleId' => $this->puzzleId,
        ]);

        $this->resetForm();
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

        // Determine source collection ID for filtering
        $sourceId = $this->sourceCollectionId;
        if ($sourceId === Collection::SYSTEM_ID) {
            $sourceId = null;
        }

        // Filter out collections that already contain this puzzle AND the source collection
        $availableCollections = [];

        // Only add system collection if not already containing the puzzle AND not the source
        if (in_array(null, $existingCollectionIds, true) === false && $sourceId !== null) {
            $availableCollections[] = new CollectionOverview(
                playerId: $player->playerId,
                collectionId: Collection::SYSTEM_ID,
                name: $this->translator->trans('collections.system_name'),
                description: null,
                visibility: $player->puzzleCollectionVisibility,
            );
        }

        // Add regular collections that don't already contain the puzzle AND are not the source
        foreach ($collections as $collection) {
            if (
                $collection->collectionId !== null
                && !in_array($collection->collectionId, $existingCollectionIds, true)
                && $collection->collectionId !== $this->sourceCollectionId
            ) {
                $availableCollections[] = $collection;
            }
        }

        $this->availableCollections = $availableCollections;

        return $this->availableCollections;
    }
}
