<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SocialLogin;

use SpeedPuzzling\Web\Value\OauthProvider;

/**
 * Which social sign-in providers are available, behind one door, so every
 * gate asks the same question the same way.
 *
 * A provider is available iff its credentials are configured. Asked by
 * everything that makes the provider work - start routes, the connect route,
 * the callback, the authenticators - and by templates for its buttons (Twig
 * global `social_login`: isProviderAvailable(), isAnyAvailable()). Local dev
 * and test without credentials show no button and 404 the provider's routes.
 *
 * The answer depends on configuration only - never on the visitor - so
 * /login and /register show every visitor the same buttons (those pages are
 * `no-store` anyway, NativeAuthPageSubscriber, so nothing shares a copy).
 */
final readonly class SocialLoginSettings
{
    public function __construct(
        private string $googleClientId,
        private string $googleClientSecret,
        private string $facebookAppId,
        private string $facebookAppSecret,
        private string $appleClientId,
        private string $appleTeamId,
        private string $appleKeyId,
        private string $applePrivateKey,
        private string $microsoftClientId,
        private string $microsoftClientSecret,
    ) {
    }

    public function isAvailable(OauthProvider $provider): bool
    {
        return match ($provider) {
            OauthProvider::Google => self::allConfigured($this->googleClientId, $this->googleClientSecret),
            OauthProvider::Microsoft => self::allConfigured($this->microsoftClientId, $this->microsoftClientSecret),
            OauthProvider::Facebook => self::allConfigured($this->facebookAppId, $this->facebookAppSecret),
            OauthProvider::Apple => self::allConfigured($this->appleClientId, $this->appleTeamId, $this->appleKeyId, $this->applePrivateKey),
        };
    }

    /**
     * Twig-friendly variant: `social_login.isProviderAvailable('google')`.
     */
    public function isProviderAvailable(string $provider): bool
    {
        $oauthProvider = OauthProvider::tryFrom($provider);

        return $oauthProvider !== null && $this->isAvailable($oauthProvider);
    }

    public function isAnyAvailable(): bool
    {
        foreach (OauthProvider::cases() as $provider) {
            if ($this->isAvailable($provider)) {
                return true;
            }
        }

        return false;
    }

    private static function allConfigured(string ...$values): bool
    {
        foreach ($values as $value) {
            if (trim($value) === '') {
                return false;
            }
        }

        return true;
    }
}
