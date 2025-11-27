<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Message\AddPuzzleToWishList;
use SpeedPuzzling\Web\Query\GetWishListItems;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class AddPuzzleToWishListForm
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    #[LiveProp]
    public string $puzzleId = '';

    #[LiveProp(writable: true)]
    public bool $removeOnCollectionAdd = true;

    public function __construct(
        readonly private GetWishListItems $getWishListItems,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    public function isLoggedIn(): bool
    {
        return $this->retrieveLoggedUserProfile->getProfile() !== null;
    }

    public function isPuzzleInWishList(): bool
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return false;
        }

        return $this->getWishListItems->isPuzzleInWishList($player->playerId, $this->puzzleId);
    }

    #[LiveAction]
    public function save(): void
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            $this->dispatchBrowserEvent('toast:show', [
                'message' => $this->translator->trans('wish_list.flash.login_required'),
                'type' => 'error',
            ]);

            return;
        }

        $this->messageBus->dispatch(new AddPuzzleToWishList(
            playerId: $player->playerId,
            puzzleId: $this->puzzleId,
            removeOnCollectionAdd: $this->removeOnCollectionAdd,
        ));

        $this->dispatchBrowserEvent('toast:show', [
            'message' => $this->translator->trans('wish_list.add.success'),
            'type' => 'success',
        ]);

        $this->dispatchBrowserEvent('modal:close');

        $this->emit('puzzle:addedToWishList', [
            'puzzleId' => $this->puzzleId,
        ]);
    }

    #[LiveListener('puzzle:removedFromWishList')]
    public function onWishListChanged(): void
    {
        // This will trigger a re-render
    }
}
