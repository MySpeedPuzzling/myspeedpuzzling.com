<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\FormData\PassLentPuzzleFormData;
use SpeedPuzzling\Web\FormType\PassLentPuzzleFormType;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Live Component for pass puzzle form UI.
 * Provides favorite player selection and form validation.
 * Form submission is handled by PassPuzzleController which returns Turbo Streams.
 */
#[AsLiveComponent]
final class PassLentPuzzleForm extends AbstractController
{
    use DefaultActionTrait;
    use ComponentWithFormTrait;

    #[LiveProp]
    public string $lentPuzzleId = '';

    #[LiveProp]
    public string $context = 'detail';

    #[LiveProp]
    public string $tab = '';

    #[LiveProp]
    public string $collectionId = '';

    public function __construct(
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
}
