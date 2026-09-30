<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SocialLogin;

use SpeedPuzzling\Web\Value\OauthProvider;

/**
 * Which social sign-in providers are available, behind one door, so every
 * gate - button rendering, start routes, authenticators, callbacks - asks the
 * same question the same way.
 *
 * A provider is available iff its credentials are configured (local dev and
 * test without credentials render no button and 404 its routes). Facebook
 * additionally needs its SOCIAL_LOGIN_FACEBOOK_ENABLED flag until the Meta app
 * is published (docs/features/feature_flags.md).
 *
 * The answer depends on configuration only - never on the visitor - so
 * /login and /register show every visitor the same buttons (those pages are
 * `no-store` anyway, NativeAuthPageSubscriber, so nothing shares a copy).
 */
final readonly class SocialLoginSettings
{
    public function __construct(
        private bool $socialLoginFacebookEnabled,
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

    public function isEnabled(OauthProvider $provider): bool
    {
        return match ($provider) {
            OauthProvider::Google => self::allConfigured($this->googleClientId, $this->googleClientSecret),
            OauthProvider::Microsoft => self::allConfigured($this->microsoftClientId, $this->microsoftClientSecret),
            OauthProvider::Facebook => $this->socialLoginFacebookEnabled
                && self::allConfigured($this->facebookAppId, $this->facebookAppSecret),
            OauthProvider::Apple => self::allConfigured($this->appleClientId, $this->appleTeamId, $this->appleKeyId, $this->applePrivateKey),
        };
    }

    /**
     * Twig-friendly variant: `social_login.isProviderEnabled('google')`.
     */
    public function isProviderEnabled(string $provider): bool
    {
        $oauthProvider = OauthProvider::tryFrom($provider);

        return $oauthProvider !== null && $this->isEnabled($oauthProvider);
    }

    public function isAnyEnabled(): bool
    {
        foreach (OauthProvider::cases() as $provider) {
            if ($this->isEnabled($provider)) {
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
