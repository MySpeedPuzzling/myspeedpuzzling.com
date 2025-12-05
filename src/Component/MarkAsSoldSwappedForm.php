<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\SellSwapListItemNotFound;
use SpeedPuzzling\Web\FormData\MarkAsSoldSwappedFormData;
use SpeedPuzzling\Web\FormType\MarkAsSoldSwappedFormType;
use SpeedPuzzling\Web\Message\MarkPuzzleAsSoldOrSwapped;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class MarkAsSoldSwappedForm extends AbstractController
{
    use DefaultActionTrait;
    use ComponentToolsTrait;
    use ComponentWithFormTrait;

    #[LiveProp]
    public string $sellSwapListItemId = '';

    #[LiveProp]
    public string $puzzleId = '';

    #[LiveProp(writable: true)]
    public null|string $buyerInput = null;

    public function __construct(
        readonly private GetFavoritePlayers $getFavoritePlayers,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<PlayerIdentification>
     */
    public function getFavoritePlayers(): array
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return [];
        }

        try {
            return $this->getFavoritePlayers->forPlayerId($player->playerId);
        } catch (PlayerNotFound) {
            return [];
        }
    }

    /**
     * @return FormInterface<MarkAsSoldSwappedFormData>
     */
    protected function instantiateForm(): FormInterface
    {
        $formData = new MarkAsSoldSwappedFormData();

        return $this->createForm(MarkAsSoldSwappedFormType::class, $formData);
    }

    public function isLoggedIn(): bool
    {
        return $this->retrieveLoggedUserProfile->getProfile() !== null;
    }

    /**
     * @throws PlayerNotFound
     * @throws SellSwapListItemNotFound
     */
    #[LiveAction]
    public function save(): void
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            throw new PlayerNotFound();
        }

        $this->submitForm();

        /** @var MarkAsSoldSwappedFormData $formData */
        $formData = $this->getForm()->getData();

        $this->messageBus->dispatch(new MarkPuzzleAsSoldOrSwapped(
            sellSwapListItemId: $this->sellSwapListItemId,
            playerId: $player->playerId,
            buyerInput: $formData->buyerInput,
        ));

        $this->dispatchBrowserEvent('toast:show', [
            'message' => $this->translator->trans('sell_swap_list.mark_sold.success'),
            'type' => 'success',
        ]);

        $this->dispatchBrowserEvent('modal:close');

        $this->dispatchBrowserEvent('sellswaplist:itemRemoved', [
            'puzzleId' => $this->puzzleId,
        ]);

        $this->emit('puzzle:markedAsSoldSwapped', [
            'puzzleId' => $this->puzzleId,
            'sellSwapListItemId' => $this->sellSwapListItemId,
        ]);
    }
}
