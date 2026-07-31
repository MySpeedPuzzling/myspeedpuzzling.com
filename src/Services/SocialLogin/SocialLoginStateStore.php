<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SocialLogin;

use Psr\Cache\CacheItemPoolInterface;
use SpeedPuzzling\Web\Value\OauthFlowIntent;
use SpeedPuzzling\Web\Value\OauthFlowState;
use SpeedPuzzling\Web\Value\ParkedSocialRegistration;
use SpeedPuzzling\Web\Value\SocialUserProfile;

/**
 * Single-use, short-TTL server-side storage for the two secrets of the social
 * login flows: the OAuth `state` (+ PKCE verifier, + link-intent target
 * account) and the rule-4 parked provider profile awaiting the interstitial
 * confirmation. Cache instead of the session on purpose - see the pool comment
 * in config/packages/cache.php.
 */
final readonly class SocialLoginStateStore
{
    private const int TTL_SECONDS = 600;

    public function __construct(
        private CacheItemPoolInterface $socialLoginStateCache,
    ) {
    }

    public function storeState(string $state, OauthFlowState $payload): void
    {
        $item = $this->socialLoginStateCache->getItem(self::stateKey($state));
        $item->set($payload);
        $item->expiresAfter(self::TTL_SECONDS);

        $this->socialLoginStateCache->save($item);
    }

    /**
     * Non-consuming look: authenticator supports() must not burn the state -
     * the request may belong to another authenticator or the link controller.
     */
    public function peekState(null|string $state): null|OauthFlowState
    {
        $key = self::safeStateKey($state);

        if ($key === null) {
            return null;
        }

        $item = $this->socialLoginStateCache->getItem($key);
        $payload = $item->isHit() ? $item->get() : null;

        return $payload instanceof OauthFlowState ? $payload : null;
    }

    /**
     * Lookup + delete: replaying a state (or racing two callbacks on it) fails.
     */
    public function consumeState(null|string $state): null|OauthFlowState
    {
        $payload = $this->peekState($state);

        if ($payload !== null && $state !== null) {
            $this->socialLoginStateCache->deleteItem(self::stateKey($state));
        }

        return $payload;
    }

    public function peekIntent(null|string $state): null|OauthFlowIntent
    {
        return $this->peekState($state)?->intent;
    }

    /**
     * @return string the single-use interstitial token (also the CSRF guard of
     *         the confirmation POST - unguessable and bound to this profile)
     */
    public function parkRegistration(SocialUserProfile $profile, null|string $locale): string
    {
        $token = bin2hex(random_bytes(16));

        $item = $this->socialLoginStateCache->getItem(self::registrationKey($token));
        $item->set(new ParkedSocialRegistration($profile, $locale));
        $item->expiresAfter(self::TTL_SECONDS);

        $this->socialLoginStateCache->save($item);

        return $token;
    }

    public function peekRegistration(null|string $token): null|ParkedSocialRegistration
    {
        $key = self::safeRegistrationKey($token);

        if ($key === null) {
            return null;
        }

        $item = $this->socialLoginStateCache->getItem($key);
        $payload = $item->isHit() ? $item->get() : null;

        return $payload instanceof ParkedSocialRegistration ? $payload : null;
    }

    public function consumeRegistration(null|string $token): null|ParkedSocialRegistration
    {
        $payload = $this->peekRegistration($token);

        if ($payload !== null && $token !== null) {
            $this->socialLoginStateCache->deleteItem(self::registrationKey($token));
        }

        return $payload;
    }

    private static function stateKey(string $state): string
    {
        return 'oauth_state_' . $state;
    }

    /**
     * Request-provided values must never reach the cache backend raw - PSR-6
     * key charset is restricted and league states are plain hex/alnum anyway.
     */
    private static function safeStateKey(null|string $state): null|string
    {
        if ($state === null || preg_match('/^[A-Za-z0-9]{16,128}$/', $state) !== 1) {
            return null;
        }

        return self::stateKey($state);
    }

    private static function registrationKey(string $token): string
    {
        return 'oauth_registration_' . $token;
    }

    private static function safeRegistrationKey(null|string $token): null|string
    {
        if ($token === null || preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return null;
        }

        return self::registrationKey($token);
    }
}
