<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use SpeedPuzzling\Web\Security\TricklePasswordVerifier;
use SpeedPuzzling\Web\Security\TrickleVerificationResult;

/**
 * Replaces Auth0TrickleGateway in the test environment (config/services_test.php)
 * so the trickle branch is testable without any real Auth0 tenant. The verdict is
 * derived from the submitted password.
 */
final class PredictableTrickleVerifier implements TricklePasswordVerifier
{
    public const string CORRECT_PASSWORD = 'trickle-correct-password';
    public const string LEAKED_PASSWORD = 'trickle-leaked-password';
    public const string AUTH0_DOWN_PASSWORD = 'trickle-auth0-down-password';

    /**
     * Static so call tracking survives the kernel reboot between two requests
     * of the same KernelBrowser test - reset it in setUp().
     *
     * @var list<string> emails passed to verify(), in call order
     */
    private static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    /**
     * @return list<string>
     *
     * @phpstan-impure the backing array mutates with every request the browser makes
     */
    public static function calls(): array
    {
        return self::$calls;
    }

    public function verify(string $email, string $plainPassword, null|string $clientIp): TrickleVerificationResult
    {
        self::$calls[] = $email;

        return match ($plainPassword) {
            self::CORRECT_PASSWORD => TrickleVerificationResult::Verified,
            self::LEAKED_PASSWORD => TrickleVerificationResult::PasswordLeaked,
            self::AUTH0_DOWN_PASSWORD => TrickleVerificationResult::Unavailable,
            default => TrickleVerificationResult::InvalidCredentials,
        };
    }
}
