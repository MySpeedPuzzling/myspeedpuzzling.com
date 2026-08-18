<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Auth0\Symfony\Models\User;
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

        if ($user instanceof User) {
            $this->foundProfile = $this->findProfileRegisteringIfMissing(
                $user->getUserIdentifier(),
                $user->getEmail(),
                $user->getName(),
            );
        } elseif ($user instanceof UserAccount) {
            $this->foundProfile = $this->findProfileRegisteringIfMissing(
                $user->getUserIdentifier(),
                $user->email,
                null,
            );
        }

        return $this->foundProfile;
    }

    private function findProfileRegisteringIfMissing(
        string $userId,
        null|string $email,
        null|string $name,
    ): null|PlayerProfile {
        try {
            return $this->getPlayerProfile->byUserId($userId);
        } catch (PlayerNotFound) {
            // Auth0: user just came from registration -> has userId but no Player exists in db yet.
            // Native accounts get their Player atomically in RegisterUserHandler, so for them
            // this JIT registration is a safety net only.
            $this->messageBus->dispatch(
                new RegisterUserToPlay($userId, $email, $name),
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
