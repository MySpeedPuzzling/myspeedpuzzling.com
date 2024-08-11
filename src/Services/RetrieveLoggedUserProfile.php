<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Auth0\Symfony\Models\User;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\HideWjpcModal;
use SpeedPuzzling\Web\Message\RegisterUserToPlay;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Results\PlayerProfile;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\MessageBusInterface;

final class RetrieveLoggedUserProfile
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
            $userId = $user->getUserIdentifier();

            try {
                $this->foundProfile = $this->getPlayerProfile->byUserId($userId);

                $this->messageBus->dispatch(
                    new HideWjpcModal($this->foundProfile->playerId)
                );
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
                    $this->foundProfile = $this->getPlayerProfile->byUserId($userId);

                    $this->messageBus->dispatch(
                        new HideWjpcModal($this->foundProfile->playerId)
                    );
                } catch (PlayerNotFound $e) {
                    $this->logger->critical('Could not create player profile for logged in user.', [
                        'user_id' => $userId,
                        'exception' => $e,
                    ]);
                }
            }
        }

        return $this->foundProfile;
    }
}
