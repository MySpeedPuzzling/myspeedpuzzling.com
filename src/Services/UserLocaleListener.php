<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Message\UpdatePlayerLocale;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsEventListener]
readonly final class UserLocaleListener
{
    public function __construct(
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if ($event->isMainRequest() === false) {
            // don't do anything if it's not the main request
            return;
        }

        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile !== null) {
            $request = $event->getRequest();
            $locale = $request->getLocale();

            if ($locale !== $profile->locale) {
                $this->messageBus->dispatch(
                    new UpdatePlayerLocale(
                        playerId: $profile->playerId,
                        locale: $locale,
                    ),
                );
            }
        }
    }
}
