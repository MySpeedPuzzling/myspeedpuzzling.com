<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use SpeedPuzzling\Web\Tests\TestDouble\AppleIdTokenFactory;
use SpeedPuzzling\Web\Tests\TestDouble\MicrosoftIdTokenFactory;
use SpeedPuzzling\Web\Value\OauthProvider;

/**
 * Makes a social login provider available (or not) for one test and puts the
 * env back afterwards - call restoreSocialLoginEnv() in tearDown().
 *
 * A provider is available iff its credentials are configured, see
 * SocialLoginSettings. The repo's .env leaves every credential empty, so by default no provider is
 * available in tests.
 */
trait ConfiguresSocialLoginProviders
{
    /** @var array<string, string|false> original value, or false when it was unset */
    private array $originalSocialLoginEnv = [];

    private function enableSocialLoginProvider(OauthProvider ...$providers): void
    {
        foreach ($providers as $provider) {
            foreach (self::socialLoginTestCredentials($provider) as $name => $value) {
                $this->overrideSocialLoginEnv($name, $value);
            }
        }
    }

    private function disableSocialLoginProvider(OauthProvider ...$providers): void
    {
        foreach ($providers as $provider) {
            foreach (array_keys(self::socialLoginTestCredentials($provider)) as $name) {
                $this->overrideSocialLoginEnv($name, '');
            }
        }
    }

    private function overrideSocialLoginEnv(string $name, string $value): void
    {
        if (!array_key_exists($name, $this->originalSocialLoginEnv)) {
            $original = $_ENV[$name] ?? false;
            $this->originalSocialLoginEnv[$name] = is_string($original) ? $original : false;
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private function restoreSocialLoginEnv(): void
    {
        foreach ($this->originalSocialLoginEnv as $name => $original) {
            if ($original === false) {
                unset($_ENV[$name], $_SERVER[$name]);

                continue;
            }

            $_ENV[$name] = $original;
            $_SERVER[$name] = $original;
        }

        $this->originalSocialLoginEnv = [];
    }

    /**
     * @return array<string, string>
     */
    private static function socialLoginTestCredentials(OauthProvider $provider): array
    {
        return match ($provider) {
            OauthProvider::Google => [
                'GOOGLE_CLIENT_ID' => 'test-google-client-id',
                'GOOGLE_CLIENT_SECRET' => 'test-google-client-secret',
            ],
            // The id_token audience must match what MicrosoftIdTokenFactory signs
            OauthProvider::Microsoft => [
                'MICROSOFT_CLIENT_ID' => MicrosoftIdTokenFactory::CLIENT_ID,
                'MICROSOFT_CLIENT_SECRET' => 'test-microsoft-client-secret',
            ],
            OauthProvider::Facebook => [
                'FACEBOOK_APP_ID' => 'test-facebook-app-id',
                'FACEBOOK_APP_SECRET' => 'test-facebook-app-secret',
            ],
            // Apple's id_token audience and client-secret signing key must match
            // what AppleIdTokenFactory produces
            OauthProvider::Apple => [
                'APPLE_CLIENT_ID' => AppleIdTokenFactory::CLIENT_ID,
                'APPLE_TEAM_ID' => 'TESTTEAM01',
                'APPLE_KEY_ID' => 'TESTKEY001',
                'APPLE_PRIVATE_KEY' => AppleIdTokenFactory::clientSecretKeyPem(),
            ],
        };
    }
}
