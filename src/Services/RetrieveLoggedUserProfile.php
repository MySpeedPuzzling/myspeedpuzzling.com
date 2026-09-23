<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\RegisterUserToPlay;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Results\PlayerProfile;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\ResetInterface;

final class RetrieveLoggedUserProfile implements ResetInterface
{
    private bool $populated = false;

    private null|PlayerProfile $foundProfile = null;

    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private Security $security,
        readonly private MessageBusInterface $messageBus,
        readonly private LoggerInterface $logger,
    ) {
    }

    public function getProfile(): null|PlayerProfile
    {
        if ($this->populated === true) {
            return $this->foundProfile;
        }

        $user = $this->security->getUser();
        $this->populated = true;

        if ($user instanceof UserAccount) {
            $this->foundProfile = $this->findProfileRegisteringIfMissing($user);
        }

        return $this->foundProfile;
    }

    private function findProfileRegisteringIfMissing(UserAccount $userAccount): null|PlayerProfile
    {
        $userId = $userAccount->getUserIdentifier();

        try {
            return $this->getPlayerProfile->byUserId($userId);
        } catch (PlayerNotFound) {
            // Safety net: registration (native and social) creates the account and
            // its player atomically, but a handful of accounts imported from Auth0
            // never had a player - their owners registered on Auth0 and never came
            // back. Their first sign-in gets the player here.
            $this->messageBus->dispatch(
                new RegisterUserToPlay($userId, null),
            );

            try {
                return $this->getPlayerProfile->byUserId($userId);
            } catch (PlayerNotFound $e) {
                $this->logger->critical('Could not create player profile for logged in user.', [
                    'user_id' => $userId,
                    'exception' => $e,
                ]);

                return null;
            }
        }
    }

    public function reset(): void
    {
        $this->populated = false;
        $this->foundProfile = null;
    }
}
