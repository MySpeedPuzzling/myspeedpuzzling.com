<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Value\SignInCodeOutcome;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * A typed sign-in code that did not sign in (SignInCodeAuthenticator). Carries
 * what the "Check your email" screen needs to say so - never the code itself.
 */
final class SignInCodeRejected extends AuthenticationException
{
    public function __construct(
        public readonly SignInCodeOutcome $outcome,
        /** The pending sign-in's address, null when nothing is pending in this browser */
        public readonly null|string $email,
        public readonly int $attemptsLeft = 0,
    ) {
        parent::__construct(sprintf('Sign-in code rejected: %s', $outcome->value));
    }

    public function getMessageKey(): string
    {
        return 'Invalid credentials.';
    }
}
