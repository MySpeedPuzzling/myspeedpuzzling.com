<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Result of VerifySignInCodeHandler. The handler reports instead of throwing:
 * a wrong code must still be counted, and a handler that throws is rolled back.
 */
final readonly class SignInCodeCheck
{
    private function __construct(
        public SignInCodeOutcome $outcome,
        public null|string $userId = null,
        public null|string $returnPath = null,
        public int $attemptsLeft = 0,
    ) {
    }

    public static function accepted(string $userId, null|string $returnPath): self
    {
        return new self(SignInCodeOutcome::Accepted, $userId, $returnPath);
    }

    public static function wrong(int $attemptsLeft): self
    {
        return $attemptsLeft > 0
            ? new self(SignInCodeOutcome::Wrong, attemptsLeft: $attemptsLeft)
            : new self(SignInCodeOutcome::LockedOut);
    }

    public static function rejected(SignInCodeOutcome $outcome): self
    {
        return new self($outcome);
    }
}
