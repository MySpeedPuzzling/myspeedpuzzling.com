<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\RemovePuzzleFromCollection;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class RemovePuzzleFromCollectionButton
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    #[LiveProp]
    public string $puzzleId = '';

    #[LiveProp]
    public null|string $collectionId = null;

    #[LiveProp]
    public string $collectionName = '';

    #[LiveProp]
    public string $buttonClass = 'dropdown-item text-danger';

    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
    ) {
    }

    public function isLoggedIn(): bool
    {
        return $this->retrieveLoggedUserProfile->getProfile() !== null;
    }

    /**
     * @throws PlayerNotFound
     */
    #[LiveAction]
    public function remove(): void
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            throw new PlayerNotFound();
        }

        $collectionId = $this->collectionId;

        if ($collectionId === '' || $collectionId === null) {
            $collectionId = null;
        }

        $this->messageBus->dispatch(new RemovePuzzleFromCollection(
            playerId: $player->playerId,
            puzzleId: $this->puzzleId,
            collectionId: $collectionId,
        ));

        $this->dispatchBrowserEvent('toast:show', [
            'message' => $this->translator->trans('collections.flash.puzzle_removed'),
            'type' => 'success',
        ]);

        $this->emit('puzzle:removedFromCollection', [
            'puzzleId' => $this->puzzleId,
            'collectionId' => $this->collectionId,
        ]);
    }
}
