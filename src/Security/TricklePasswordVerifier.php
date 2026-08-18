<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

/**
 * Verifies a password for an imported Auth0 account that has no local hash yet
 * ("trickle migration", decision D4 in docs/features/auth-migration/README.md).
 * Behind an interface so tests can stub it and Phase 6 can delete the Auth0
 * implementation without touching the authenticator's contract.
 */
interface TricklePasswordVerifier
{
    public function verify(string $email, string $plainPassword, null|string $clientIp): TrickleVerificationResult;
}
