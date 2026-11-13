<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Message\GrantSandboxMembership;
use SpeedPuzzling\Web\Services\Sandbox\SandboxMode;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * On the sandbox every sign-in - right after registration too, the registration logs the new account in - makes sure
 * the player is a member (docs/features/sandbox/README.md "Membership"). Off the sandbox it does nothing at all.
 * A failure is logged and never blocks the sign-in.
 */
final readonly class SandboxMembershipOnLoginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private SandboxMode $sandboxMode,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if ($this->sandboxMode->on === false) {
            return;
        }

        $user = $event->getUser();

        if (!$user instanceof UserAccount) {
            return;
        }

        try {
            $this->messageBus->dispatch(new GrantSandboxMembership($user->userId));
        } catch (\Throwable $e) {
            $this->logger->warning('Sandbox membership could not be granted on sign-in', [
                'exception' => $e,
                'userId' => $user->userId,
            ]);
        }
    }
}
