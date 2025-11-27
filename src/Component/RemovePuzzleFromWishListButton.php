<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\RemovePuzzleFromWishList;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class RemovePuzzleFromWishListButton
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    #[LiveProp]
    public string $puzzleId = '';

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

        $this->messageBus->dispatch(new RemovePuzzleFromWishList(
            playerId: $player->playerId,
            puzzleId: $this->puzzleId,
        ));

        $this->dispatchBrowserEvent('toast:show', [
            'message' => $this->translator->trans('wish_list.remove.success'),
            'type' => 'success',
        ]);

        $this->dispatchBrowserEvent('wishlist:itemRemoved', [
            'puzzleId' => $this->puzzleId,
        ]);

        $this->emit('puzzle:removedFromWishList', [
            'puzzleId' => $this->puzzleId,
        ]);
    }
}
