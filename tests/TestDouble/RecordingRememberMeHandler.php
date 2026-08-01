<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use LogicException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\RememberMe\RememberMeDetails;
use Symfony\Component\Security\Http\RememberMe\RememberMeHandlerInterface;

/**
 * Counts cookie writes for MigrationWindowRememberMeListenerTest. A hand-written
 * double rather than a mock: the assertions are about how many times the cookie
 * was cleared, which reads clearer as a plain counter than as expectations.
 */
final class RecordingRememberMeHandler implements RememberMeHandlerInterface
{
    public int $clearCalls = 0;

    public int $createCalls = 0;

    public function createRememberMeCookie(UserInterface $user): void
    {
        $this->createCalls++;
    }

    public function clearRememberMeCookie(): void
    {
        $this->clearCalls++;
    }

    public function consumeRememberMeCookie(RememberMeDetails $rememberMeDetails): UserInterface
    {
        throw new LogicException('Not needed for these tests.');
    }
}
