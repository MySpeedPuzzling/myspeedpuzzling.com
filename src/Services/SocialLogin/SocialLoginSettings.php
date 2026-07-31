<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SocialLogin;

use SpeedPuzzling\Web\Value\OauthProvider;

/**
 * The four social-login feature flags (docs/features/feature_flags.md) behind
 * one door, so every gate - button rendering, start routes, authenticators,
 * link/unlink/registration handlers - asks the same question the same way.
 */
final readonly class SocialLoginSettings
{
    public function __construct(
        private bool $socialLoginAdminOnly,
        private bool $socialLoginGoogleEnabled,
        private bool $socialLoginFacebookEnabled,
        private bool $socialLoginAppleEnabled,
    ) {
    }

    public function isEnabled(OauthProvider $provider): bool
    {
        return match ($provider) {
            OauthProvider::Google => $this->socialLoginGoogleEnabled,
            OauthProvider::Facebook => $this->socialLoginFacebookEnabled,
            OauthProvider::Apple => $this->socialLoginAppleEnabled,
        };
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

    /**
     * Staged rollout (plan §Feature flags + admin-only rollout stage): while ON,
     * social login is invisible to the public - no buttons anywhere, callbacks
     * deny non-admin accounts with a generic failure, and rule-4 registration
     * is disabled entirely.
     */
    public function isAdminOnly(): bool
    {
        return $this->socialLoginAdminOnly;
    }
}
