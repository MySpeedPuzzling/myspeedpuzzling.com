<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Message\HideModal;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsEventListener]
readonly final class HideModalListener
{
    public function __construct(
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if ($event->isMainRequest() === false) {
            // don't do anything if it's not the main request
            return;
        }

        $response = $event->getResponse();

        // Ensure it's an HTML response and not a redirect or JSON
        if ($response instanceof RedirectResponse || $response instanceof JsonResponse) {
            return;
        }

        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile?->modalDisplayed === false) {
            $this->messageBus->dispatch(
                new HideModal($profile->playerId),
            );
        }
    }
}
