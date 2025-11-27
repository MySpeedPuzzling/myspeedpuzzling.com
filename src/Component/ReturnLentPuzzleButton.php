<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Message\ReturnLentPuzzle;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class ReturnLentPuzzleButton extends AbstractController
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    #[LiveProp]
    public string $lentPuzzleId = '';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[LiveAction]
    public function returnPuzzle(): void
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            $this->dispatchBrowserEvent('toast:show', [
                'message' => $this->translator->trans('lend_borrow.flash.login_required'),
                'type' => 'error',
            ]);

            return;
        }

        $this->messageBus->dispatch(new ReturnLentPuzzle(
            lentPuzzleId: $this->lentPuzzleId,
            actingPlayerId: $player->playerId,
        ));

        $this->dispatchBrowserEvent('toast:show', [
            'message' => $this->translator->trans('lend_borrow.flash.returned'),
            'type' => 'success',
        ]);

        $this->emit('puzzle:returned', [
            'lentPuzzleId' => $this->lentPuzzleId,
        ]);
    }
}
