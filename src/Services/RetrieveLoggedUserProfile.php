<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Auth0\Symfony\Models\User;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\RegisterUserToPlay;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Results\PlayerProfile;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\MessageBusInterface;

readonly final class RetrieveLoggedUserProfile
{
    public function __construct(
        private GetPlayerProfile $getPlayerProfile,
        private Security $security,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function getProfile(): null|PlayerProfile
    {
        $user = $this->security->getUser();

        if ($user instanceof User) {
            $userId = $user->getUserIdentifier();

            try {
                return $this->getPlayerProfile->byUserId($userId);
            } catch (PlayerNotFound) {
                // Case that user just came from registration -> has userId but no Player exists in db yet
                $this->messageBus->dispatch(
                    new RegisterUserToPlay(
                        $userId,
                        $user->getEmail(),
                        $user->getName(),
                    )
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

        return null;
    }
}
