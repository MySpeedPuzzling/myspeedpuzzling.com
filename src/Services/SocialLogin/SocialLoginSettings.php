<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SocialLogin;

use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Which social sign-in providers are available and which are shown, behind
 * one door, so every gate asks the same question the same way.
 *
 * - isAvailable(): the provider's credentials are configured. Asked by
 *   everything that makes the provider WORK - start routes, the connect
 *   route, the callback, the authenticators. Local dev and test without
 *   credentials 404 the provider's routes.
 * - isShown(): available, and its buttons may be offered. Asked by templates
 *   (Twig global `social_login`: isProviderShown(), isAnyShown()). Facebook's
 *   buttons additionally need SOCIAL_LOGIN_FACEBOOK_ENABLED until the Meta app
 *   is published - its routes work without it, for testing by direct URL
 *   (docs/features/feature_flags.md).
 *
 * The answer depends on configuration only - never on the visitor - so
 * /login and /register show every visitor the same buttons (those pages are
 * `no-store` anyway, NativeAuthPageSubscriber, so nothing shares a copy).
 * The one exception is the Facebook review preview below.
 *
 * Facebook review preview - exists ONLY for Meta App Review: the reviewer must
 * see and click "Continue with Facebook" while the flag still hides it. A
 * request with `?facebook_preview=1`, or carrying the `msp_fb_preview` cookie
 * (set for a day by NativeAuthPageSubscriber on auth pages that got the query
 * parameter, so it survives login -> register -> edit profile), shows
 * Facebook as if the flag were on - still only when its credentials are
 * configured. Remove it together with the flag once the Meta app is published
 * (docs/features/feature_flags.md).
 */
final readonly class SocialLoginSettings
{
    public const string FACEBOOK_PREVIEW_QUERY = 'facebook_preview';

    public const string FACEBOOK_PREVIEW_COOKIE = 'msp_fb_preview';

    public function __construct(
        private RequestStack $requestStack,
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

    public function isAvailable(OauthProvider $provider): bool
    {
        return match ($provider) {
            OauthProvider::Google => self::allConfigured($this->googleClientId, $this->googleClientSecret),
            OauthProvider::Microsoft => self::allConfigured($this->microsoftClientId, $this->microsoftClientSecret),
            OauthProvider::Facebook => self::allConfigured($this->facebookAppId, $this->facebookAppSecret),
            OauthProvider::Apple => self::allConfigured($this->appleClientId, $this->appleTeamId, $this->appleKeyId, $this->applePrivateKey),
        };
    }

    public function isShown(OauthProvider $provider): bool
    {
        if ($this->isAvailable($provider) === false) {
            return false;
        }

        return $provider !== OauthProvider::Facebook
            || $this->socialLoginFacebookEnabled
            || $this->isFacebookPreviewRequested();
    }

    /**
     * Meta App Review preview, see the class comment - removed with the flag.
     */
    public function isFacebookPreviewRequested(): bool
    {
        $request = $this->requestStack->getMainRequest();

        if ($request === null) {
            return false;
        }

        return $request->query->get(self::FACEBOOK_PREVIEW_QUERY) === '1'
            || $request->cookies->get(self::FACEBOOK_PREVIEW_COOKIE) === '1';
    }

    /**
     * Twig-friendly variant: `social_login.isProviderShown('google')`.
     */
    public function isProviderShown(string $provider): bool
    {
        $oauthProvider = OauthProvider::tryFrom($provider);

        return $oauthProvider !== null && $this->isShown($oauthProvider);
    }

    public function isAnyShown(): bool
    {
        foreach (OauthProvider::cases() as $provider) {
            if ($this->isShown($provider)) {
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
