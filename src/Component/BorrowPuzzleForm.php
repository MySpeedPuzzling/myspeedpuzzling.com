<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\FormData\BorrowPuzzleFormData;
use SpeedPuzzling\Web\FormType\BorrowPuzzleFormType;
use SpeedPuzzling\Web\Message\BorrowPuzzleFromPlayer;
use SpeedPuzzling\Web\Query\GetBorrowedPuzzles;
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
final class BorrowPuzzleForm extends AbstractController
{
    use DefaultActionTrait;
    use ComponentToolsTrait;
    use ComponentWithFormTrait;

    #[LiveProp]
    public string $puzzleId = '';

    public function __construct(
        readonly private GetBorrowedPuzzles $getBorrowedPuzzles,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PlayerRepository $playerRepository,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return FormInterface<BorrowPuzzleFormData>
     */
    protected function instantiateForm(): FormInterface
    {
        $formData = new BorrowPuzzleFormData();

        return $this->createForm(BorrowPuzzleFormType::class, $formData);
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

    public function isPuzzleAlreadyBorrowed(): bool
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return false;
        }

        return $this->getBorrowedPuzzles->isPuzzleBorrowedByHolder($player->playerId, $this->puzzleId);
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

        /** @var BorrowPuzzleFormData $formData */
        $formData = $this->getForm()->getData();

        if ($formData->ownerCode === null || trim($formData->ownerCode) === '') {
            $this->dispatchBrowserEvent('toast:show', [
                'message' => $this->translator->trans('lend_borrow.flash.validation_error'),
                'type' => 'error',
            ]);

            return;
        }

        // Parse input - if starts with # try to find registered player, otherwise use as plain text
        $input = $formData->ownerCode;
        $isRegisteredPlayer = str_starts_with($input, '#');
        $cleanedInput = trim($input, "# \t\n\r\0");

        $ownerPlayerId = null;
        $ownerName = null;

        if ($isRegisteredPlayer) {
            try {
                $owner = $this->playerRepository->getByCode($cleanedInput);
                $ownerPlayerId = $owner->id->toString();

                // Cannot borrow from yourself
                if ($ownerPlayerId === $player->playerId) {
                    $this->dispatchBrowserEvent('toast:show', [
                        'message' => $this->translator->trans('lend_borrow.flash.cannot_borrow_from_self'),
                        'type' => 'error',
                    ]);

                    return;
                }
            } catch (PlayerNotFound) {
                $this->dispatchBrowserEvent('toast:show', [
                    'message' => $this->translator->trans('lend_borrow.flash.player_not_found'),
                    'type' => 'error',
                ]);

                return;
            }
        } else {
            // Use plain text name for non-registered person
            $ownerName = $cleanedInput;
        }

        $this->messageBus->dispatch(new BorrowPuzzleFromPlayer(
            borrowerPlayerId: $player->playerId,
            puzzleId: $this->puzzleId,
            ownerPlayerId: $ownerPlayerId,
            ownerName: $ownerName,
            notes: $formData->notes,
        ));

        $this->dispatchBrowserEvent('toast:show', [
            'message' => $this->translator->trans('lend_borrow.flash.borrowed'),
            'type' => 'success',
        ]);

        $this->dispatchBrowserEvent('modal:close');

        $this->emit('puzzle:borrowed', [
            'puzzleId' => $this->puzzleId,
        ]);
    }
}
