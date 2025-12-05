<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\FormData\LendPuzzleFormData;
use SpeedPuzzling\Web\FormType\LendPuzzleFormType;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Query\GetLentPuzzles;
use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Live Component for lend puzzle form UI.
 * Provides favorite player selection and form validation.
 * Form submission is handled by LendPuzzleController which returns Turbo Streams.
 */
#[AsLiveComponent]
final class LendPuzzleForm extends AbstractController
{
    use DefaultActionTrait;
    use ComponentWithFormTrait;

    #[LiveProp]
    public string $puzzleId = '';

    public function __construct(
        readonly private GetLentPuzzles $getLentPuzzles,
        readonly private GetFavoritePlayers $getFavoritePlayers,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
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
}
