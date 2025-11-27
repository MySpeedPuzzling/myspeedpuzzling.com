<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\FormData\PassLentPuzzleFormData;
use SpeedPuzzling\Web\FormType\PassLentPuzzleFormType;
use SpeedPuzzling\Web\Message\PassLentPuzzle;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Repository\PlayerRepository;
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
final class PassLentPuzzleForm extends AbstractController
{
    use DefaultActionTrait;
    use ComponentToolsTrait;
    use ComponentWithFormTrait;

    #[LiveProp]
    public string $lentPuzzleId = '';

    public function __construct(
        readonly private GetFavoritePlayers $getFavoritePlayers,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PlayerRepository $playerRepository,
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
     * @return FormInterface<PassLentPuzzleFormData>
     */
    protected function instantiateForm(): FormInterface
    {
        $formData = new PassLentPuzzleFormData();

        return $this->createForm(PassLentPuzzleFormType::class, $formData);
    }

    public function isLoggedIn(): bool
    {
        return $this->retrieveLoggedUserProfile->getProfile() !== null;
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

        $this->submitForm();

        /** @var PassLentPuzzleFormData $formData */
        $formData = $this->getForm()->getData();

        if ($formData->newHolderCode === null || trim($formData->newHolderCode) === '') {
            $this->dispatchBrowserEvent('toast:show', [
                'message' => $this->translator->trans('lend_borrow.flash.validation_error'),
                'type' => 'error',
            ]);

            return;
        }

        // Parse input - if starts with # try to find registered player, otherwise use as plain text
        $input = $formData->newHolderCode;
        $isRegisteredPlayer = str_starts_with($input, '#');
        $cleanedInput = trim($input, "# \t\n\r\0");

        $newHolderPlayerId = null;
        $newHolderName = null;

        if ($isRegisteredPlayer) {
            try {
                $newHolder = $this->playerRepository->getByCode($cleanedInput);
                $newHolderPlayerId = $newHolder->id->toString();

                // Cannot pass to yourself
                if ($newHolderPlayerId === $player->playerId) {
                    $this->dispatchBrowserEvent('toast:show', [
                        'message' => $this->translator->trans('lend_borrow.flash.cannot_pass_to_self'),
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
            $newHolderName = $cleanedInput;
        }

        $this->messageBus->dispatch(new PassLentPuzzle(
            lentPuzzleId: $this->lentPuzzleId,
            currentHolderPlayerId: $player->playerId,
            newHolderPlayerId: $newHolderPlayerId,
            newHolderName: $newHolderName,
        ));

        $this->dispatchBrowserEvent('toast:show', [
            'message' => $this->translator->trans('lend_borrow.flash.passed'),
            'type' => 'success',
        ]);

        $this->dispatchBrowserEvent('modal:close');

        $this->emit('puzzle:passed', [
            'lentPuzzleId' => $this->lentPuzzleId,
        ]);
    }
}
