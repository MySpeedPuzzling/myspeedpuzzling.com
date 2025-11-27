<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\FormData\AddToSellSwapListFormData;
use SpeedPuzzling\Web\FormType\AddToSellSwapListFormType;
use SpeedPuzzling\Web\Message\AddPuzzleToSellSwapList;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
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
final class AddPuzzleToSellSwapListForm extends AbstractController
{
    use DefaultActionTrait;
    use ComponentToolsTrait;
    use ComponentWithFormTrait;

    #[LiveProp]
    public string $puzzleId = '';

    #[LiveProp(writable: true)]
    public null|string $listingType = null;

    #[LiveProp(writable: true)]
    public null|string $price = null;

    #[LiveProp(writable: true)]
    public null|string $condition = null;

    #[LiveProp(writable: true)]
    public null|string $comment = null;

    public function __construct(
        readonly private GetSellSwapListItems $getSellSwapListItems,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return FormInterface<AddToSellSwapListFormData>
     */
    protected function instantiateForm(): FormInterface
    {
        $formData = new AddToSellSwapListFormData();

        return $this->createForm(AddToSellSwapListFormType::class, $formData);
    }

    public function isLoggedIn(): bool
    {
        return $this->retrieveLoggedUserProfile->getProfile() !== null;
    }

    public function hasMembership(): bool
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        return $player !== null && $player->activeMembership;
    }

    public function isPuzzleInSellSwapList(): bool
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return false;
        }

        return $this->getSellSwapListItems->isPuzzleInSellSwapList($player->playerId, $this->puzzleId);
    }

    #[LiveAction]
    public function save(): void
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            $this->dispatchBrowserEvent('toast:show', [
                'message' => $this->translator->trans('sell_swap_list.flash.login_required'),
                'type' => 'error',
            ]);

            return;
        }

        if (!$player->activeMembership) {
            $this->dispatchBrowserEvent('toast:show', [
                'message' => $this->translator->trans('sell_swap_list.membership_required.message'),
                'type' => 'error',
            ]);

            return;
        }

        $this->submitForm();

        /** @var AddToSellSwapListFormData $formData */
        $formData = $this->getForm()->getData();

        if ($formData->listingType === null || $formData->condition === null) {
            $this->dispatchBrowserEvent('toast:show', [
                'message' => $this->translator->trans('sell_swap_list.flash.validation_error'),
                'type' => 'error',
            ]);

            return;
        }

        $this->messageBus->dispatch(new AddPuzzleToSellSwapList(
            playerId: $player->playerId,
            puzzleId: $this->puzzleId,
            listingType: $formData->listingType,
            price: $formData->price,
            condition: $formData->condition,
            comment: $formData->comment,
        ));

        $this->dispatchBrowserEvent('toast:show', [
            'message' => $this->translator->trans('sell_swap_list.flash.added'),
            'type' => 'success',
        ]);

        $this->dispatchBrowserEvent('modal:close');

        $this->emit('puzzle:addedToSellSwapList', [
            'puzzleId' => $this->puzzleId,
        ]);
    }

    #[LiveListener('puzzle:removedFromSellSwapList')]
    public function onSellSwapListChanged(): void
    {
        // This will trigger a re-render
    }
}
