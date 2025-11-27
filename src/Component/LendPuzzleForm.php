<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\FormData\LendPuzzleFormData;
use SpeedPuzzling\Web\FormType\LendPuzzleFormType;
use SpeedPuzzling\Web\Message\LendPuzzleToPlayer;
use SpeedPuzzling\Web\Query\GetLentPuzzles;
use SpeedPuzzling\Web\Repository\PlayerRepository;
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
final class LendPuzzleForm extends AbstractController
{
    use DefaultActionTrait;
    use ComponentToolsTrait;
    use ComponentWithFormTrait;

    #[LiveProp]
    public string $puzzleId = '';

    public function __construct(
        readonly private GetLentPuzzles $getLentPuzzles,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PlayerRepository $playerRepository,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return FormInterface<LendPuzzleFormData>
     */
    protected function instantiateForm(): FormInterface
    {
        $formData = new LendPuzzleFormData();

        return $this->createForm(LendPuzzleFormType::class, $formData);
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

    public function isPuzzleAlreadyLent(): bool
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return false;
        }

        return $this->getLentPuzzles->isPuzzleLentByOwner($player->playerId, $this->puzzleId);
    }

    #[LiveAction]
    public function save(): void
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            $this->dispatchBrowserEvent('toast:show', [
                'message' => $this->translator->trans('lend_borrow.flash.login_required'),
                'type' => 'error',
            ]);

            return;
        }

        if (!$player->activeMembership) {
            $this->dispatchBrowserEvent('toast:show', [
                'message' => $this->translator->trans('lend_borrow.membership_required.message'),
                'type' => 'error',
            ]);

            return;
        }

        $this->submitForm();

        /** @var LendPuzzleFormData $formData */
        $formData = $this->getForm()->getData();

        if ($formData->borrowerCode === null) {
            $this->dispatchBrowserEvent('toast:show', [
                'message' => $this->translator->trans('lend_borrow.flash.validation_error'),
                'type' => 'error',
            ]);

            return;
        }

        // Look up borrower by code
        try {
            $borrower = $this->playerRepository->getByCode($formData->borrowerCode);
        } catch (PlayerNotFound) {
            $this->dispatchBrowserEvent('toast:show', [
                'message' => $this->translator->trans('lend_borrow.flash.player_not_found'),
                'type' => 'error',
            ]);

            return;
        }

        // Cannot lend to yourself
        if ($borrower->id->toString() === $player->playerId) {
            $this->dispatchBrowserEvent('toast:show', [
                'message' => $this->translator->trans('lend_borrow.flash.cannot_lend_to_self'),
                'type' => 'error',
            ]);

            return;
        }

        $this->messageBus->dispatch(new LendPuzzleToPlayer(
            ownerPlayerId: $player->playerId,
            puzzleId: $this->puzzleId,
            borrowerPlayerId: $borrower->id->toString(),
            notes: $formData->notes,
        ));

        $this->dispatchBrowserEvent('toast:show', [
            'message' => $this->translator->trans('lend_borrow.flash.lent'),
            'type' => 'success',
        ]);

        $this->dispatchBrowserEvent('modal:close');

        $this->emit('puzzle:lent', [
            'puzzleId' => $this->puzzleId,
        ]);
    }
}
