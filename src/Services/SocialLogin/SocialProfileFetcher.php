<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SocialLogin;

use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\FacebookUser;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Token\AccessToken;
use SpeedPuzzling\Web\Value\OauthProvider;
use SpeedPuzzling\Web\Value\SocialUserProfile;

/**
 * Exchanges the authorization code and normalizes what each provider proved
 * into one SocialUserProfile shape (see the Value class for the per-provider
 * email-verification semantics).
 */
final readonly class SocialProfileFetcher
{
    public function __construct(
        private SocialLoginProviders $providers,
    ) {
    }

    /**
     * @param null|string $appleUserPayload Apple posts a `user` JSON field with the
     *        name ONLY on the user's first authorization - it never appears again,
     *        and it never comes from the token endpoint (plan §Provider gotchas)
     *
     * @throws IdentityProviderException
     */
    public function fetch(
        OauthProvider $provider,
        string $code,
        null|string $pkceVerifier,
        null|string $appleUserPayload = null,
    ): SocialUserProfile {
        $leagueProvider = $this->providers->create($provider);

        if ($pkceVerifier !== null) {
            $leagueProvider->setPkceCode($pkceVerifier);
        }

        $accessToken = $leagueProvider->getAccessToken('authorization_code', ['code' => $code]);
        assert($accessToken instanceof AccessToken);

        if ($provider === OauthProvider::Apple) {
            return self::appleProfile($accessToken, $appleUserPayload);
        }

        $resourceOwner = $leagueProvider->getResourceOwner($accessToken);

        if ($resourceOwner instanceof GoogleUser) {
            $email = $resourceOwner->getEmail();
            $providerUserId = $resourceOwner->getId();
            assert(is_scalar($providerUserId) && (string) $providerUserId !== '');

            return new SocialUserProfile(
                provider: OauthProvider::Google,
                providerUserId: (string) $providerUserId,
                email: $email,
                emailVerified: $email !== null && $resourceOwner->getEmailVerified() === true,
                name: $resourceOwner->getName(),
            );
        }

        assert($resourceOwner instanceof FacebookUser);
        $email = $resourceOwner->getEmail();
        $providerUserId = $resourceOwner->getId();
        assert(is_string($providerUserId) && $providerUserId !== '');

        return new SocialUserProfile(
            provider: OauthProvider::Facebook,
            providerUserId: $providerUserId,
            email: $email,
            // Facebook returns only confirmed addresses; a denied email
            // permission arrives as null (= unverified, rules 3/4 refuse)
            emailVerified: $email !== null,
            name: $resourceOwner->getName(),
        );
    }

    /**
     * Apple has no userinfo endpoint - identity comes from the id_token the
     * token endpoint returned (AppleAccessToken verified it against Apple's
     * JWKs and only exposes the email when the claim says verified).
     */
    private static function appleProfile(AccessToken $accessToken, null|string $userPayload): SocialUserProfile
    {
        $providerUserId = $accessToken->getResourceOwnerId();
        assert(is_string($providerUserId) && $providerUserId !== '');

        $email = $accessToken->getValues()['email'] ?? null;

        return new SocialUserProfile(
            provider: OauthProvider::Apple,
            providerUserId: $providerUserId,
            email: is_string($email) ? $email : null,
            emailVerified: is_string($email),
            name: self::appleName($userPayload),
        );
    }

    private static function appleName(null|string $userPayload): null|string
    {
        if ($userPayload === null || $userPayload === '') {
            return null;
        }

        try {
            $decoded = json_decode($userPayload, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded) || !isset($decoded['name']) || !is_array($decoded['name'])) {
            return null;
        }

        $firstName = $decoded['name']['firstName'] ?? null;
        $lastName = $decoded['name']['lastName'] ?? null;

        $name = trim(
            (is_string($firstName) ? $firstName : '') . ' ' . (is_string($lastName) ? $lastName : ''),
        );

        return $name === '' ? null : $name;
    }
}
