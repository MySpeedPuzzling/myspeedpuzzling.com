<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Results\PlayerProfile;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

readonly final class RetrieveLoggedUserProfile
{
    public function __construct(
        private GetPlayerProfile $getPlayerProfile,
        private Security $security,
    ) {
    }

    public function getProfile(): null|PlayerProfile
    {
        $user = $this->security->getUser();

        if ($user instanceof UserInterface) {
            try {
                return $this->getPlayerProfile->byUserId(
                    $user->getUserIdentifier(),
                );
            } catch (PlayerNotFound) {
                return null;
            }
        }

        return null;
    }
}
