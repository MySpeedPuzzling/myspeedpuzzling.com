<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\FormData\AddPuzzleToCollectionFormData;
use SpeedPuzzling\Web\FormType\AddPuzzleToCollectionFormType;
use SpeedPuzzling\Web\Message\AddPuzzleToCollection;
use SpeedPuzzling\Web\Query\GetPlayerCollections;
use SpeedPuzzling\Web\Repository\CollectionItemRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Results\CollectionOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Messenger\MessageBusInterface;
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
        readonly private PlayerRepository $playerRepository,
        readonly private PuzzleRepository $puzzleRepository,
        readonly private MessageBusInterface $messageBus,
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

        // Add regular collections
        foreach ($collections as $collection) {
            if ($collection->collectionId !== null) {
                $choices[$collection->name] = $collection->collectionId;
            }
        }

        return $choices;
    }

    #[LiveAction]
    public function save(): void
    {
        $this->submitForm();

        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            $this->dispatchBrowserEvent('toast:show', [
                'message' => 'You must be logged in to add puzzles to collections.',
                'type' => 'error',
            ]);

            return;
        }

        /** @var AddPuzzleToCollectionFormData $formData */
        $formData = $this->getForm()->getData();

        $this->messageBus->dispatch(new AddPuzzleToCollection(
            playerId: $player->playerId,
            puzzleId: $this->puzzleId,
            collectionId: $formData->collectionId,
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
        $this->emit('puzzle:addedToCollection', [
            'puzzleId' => $this->puzzleId,
        ]);
    }

    #[LiveListener('collection:created')]
    public function onCollectionCreated(): void
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

        // Get all player collections
        $collections = $this->getPlayerCollections->byPlayerId($player->playerId, true);

        // Get existing collection items for this puzzle
        $playerEntity = $this->playerRepository->get($player->playerId);
        $puzzleEntity = $this->puzzleRepository->get($this->puzzleId);
        $existingCollectionItems = $this->collectionItemRepository->findByPlayerAndPuzzle($playerEntity, $puzzleEntity);

        // Create set of existing collection IDs (including null for system collection)
        $existingCollectionIds = [];
        foreach ($existingCollectionItems as $item) {
            $existingCollectionIds[] = $item->collection?->id->toString();
        }

        // Filter out collections that already contain this puzzle
        $availableCollections = [];

        // Only add system collection if not already containing the puzzle
        if (!in_array(null, $existingCollectionIds, true)) {
            // $choices['collections.system_name'] = null; // TODO
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
