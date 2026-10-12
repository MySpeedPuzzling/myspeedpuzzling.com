<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * The sign-in form came without an address or a password. The login page shows
 * "enter your email/password" under the empty field instead of the wrong-password
 * helper - nothing was looked up and no attempt counted against the limits.
 *
 * Travels through the session to /login, hence the (un)serialize pair.
 */
final class LoginFormIncomplete extends AuthenticationException
{
    public function __construct(
        public readonly bool $emailMissing,
        public readonly bool $passwordMissing,
    ) {
        parent::__construct('Empty email or password.');
    }

    public function getMessageKey(): string
    {
        return 'Invalid credentials.';
    }

    /**
     * @return array<mixed>
     */
    public function __serialize(): array
    {
        return [parent::__serialize(), $this->emailMissing, $this->passwordMissing];
    }

    /**
     * @param array<mixed> $data
     */
    public function __unserialize(array $data): void
    {
        [$parentData, $emailMissing, $passwordMissing] = $data;
        assert(is_array($parentData) && is_bool($emailMissing) && is_bool($passwordMissing));

        $this->emailMissing = $emailMissing;
        $this->passwordMissing = $passwordMissing;
        parent::__unserialize($parentData);
    }
}
