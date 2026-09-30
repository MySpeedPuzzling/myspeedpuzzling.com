<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SocialLogin;

use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\FacebookUser;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AppleAccessToken;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Value\OauthProvider;
use SpeedPuzzling\Web\Value\SocialUserProfile;

/**
 * Exchanges the authorization code and normalizes what each provider proved
 * into one SocialUserProfile shape (see the Value class for the per-provider
 * email trust policy).
 */
final readonly class SocialProfileFetcher
{
    private const string APPLE_ISSUER = 'https://appleid.apple.com';

    /**
     * Microsoft's token endpoint answers `invalid_client` with this code in
     * `error_description` once the client secret has expired.
     */
    private const string MICROSOFT_EXPIRED_SECRET_CODE = 'AADSTS7000222';

    public function __construct(
        private SocialLoginProviders $providers,
        private MicrosoftIdTokenVerifier $microsoftIdTokenVerifier,
        private LoggerInterface $logger,
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

        try {
            $accessToken = $leagueProvider->getAccessToken('authorization_code', ['code' => $code]);
        } catch (IdentityProviderException $exception) {
            if ($provider === OauthProvider::Microsoft && self::isExpiredMicrosoftSecret($exception)) {
                // An outage, not a user problem: every Microsoft sign-in fails
                // until the secret is rotated (setup-microsoft.md, "Rotating the secret")
                $this->logger->error('Microsoft client secret expired - rotate MICROSOFT_CLIENT_SECRET.', [
                    'exception' => $exception,
                ]);
            }

            throw $exception;
        }

        assert($accessToken instanceof AccessToken);

        if ($provider === OauthProvider::Apple) {
            return $this->appleProfile($accessToken, $appleUserPayload);
        }

        if ($provider === OauthProvider::Microsoft) {
            return $this->microsoftProfile($accessToken);
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
     * Microsoft: identity comes from the id_token of the token response
     * (verified by MicrosoftIdTokenVerifier) - no userinfo call, no Graph.
     * Keyed on `oid`, never `sub` or the email (microsoft-plan.md §D2). The
     * email of every personal Microsoft account is trusted, like Facebook's
     * (§D3, owner decision 2026-09-30): the verifier only accepts tokens of
     * the consumers tenant (no work/school accounts, so no nOAuth), and
     * Microsoft account sign-up confirms an external address with a code.
     *
     * @throws \UnexpectedValueException the token is not one Microsoft issued to us
     */
    private function microsoftProfile(AccessToken $accessToken): SocialUserProfile
    {
        $claims = $this->microsoftIdTokenVerifier->verify($accessToken->getValues()['id_token'] ?? null);
        $email = $claims['email'];

        return new SocialUserProfile(
            provider: OauthProvider::Microsoft,
            providerUserId: $claims['oid'],
            email: $email,
            emailVerified: $email !== null,
            name: $claims['name'],
        );
    }

    private static function isExpiredMicrosoftSecret(IdentityProviderException $exception): bool
    {
        $body = $exception->getResponseBody();
        $description = is_array($body) ? ($body['error_description'] ?? null) : $body;

        return is_string($description) && str_contains($description, self::MICROSOFT_EXPIRED_SECRET_CODE);
    }

    /**
     * Apple has no userinfo endpoint - identity comes from the id_token the
     * token endpoint returned. AppleAccessToken verifies its signature (and
     * expiry) against Apple's JWKs and sets the resource owner id only on that
     * verified path, but everything else it derives is unreliable: it treats
     * `email_verified: "false"` (Apple sends strings too) as truthy, and it
     * checks neither the audience nor the issuer. So the claims are read here,
     * from the same already-verified token, and judged by our own rules.
     *
     * @throws \UnexpectedValueException the token is not one Apple issued to us
     */
    private function appleProfile(AccessToken $accessToken, null|string $userPayload): SocialUserProfile
    {
        if (!$accessToken instanceof AppleAccessToken) {
            throw new \UnexpectedValueException('Apple token endpoint did not produce an AppleAccessToken.');
        }

        $providerUserId = $accessToken->getResourceOwnerId();
        // Only set when the library verified the id_token signature
        assert(is_string($providerUserId) && $providerUserId !== '');

        $claims = self::jwtClaims($accessToken->getIdToken());

        if (($claims['iss'] ?? null) !== self::APPLE_ISSUER) {
            throw new \UnexpectedValueException('Apple id_token has an unexpected issuer.');
        }

        $audience = $claims['aud'] ?? null;
        $expectedAudience = $this->providers->appleClientId();

        if (
            $expectedAudience === ''
            || ($audience !== $expectedAudience && !(is_array($audience) && in_array($expectedAudience, $audience, true)))
        ) {
            throw new \UnexpectedValueException('Apple id_token was not issued to this client.');
        }

        if (($claims['sub'] ?? null) !== $providerUserId) {
            throw new \UnexpectedValueException('Apple id_token subject mismatch.');
        }

        $email = $claims['email'] ?? null;
        $email = is_string($email) && $email !== '' ? $email : null;

        return new SocialUserProfile(
            provider: OauthProvider::Apple,
            providerUserId: $providerUserId,
            email: $email,
            emailVerified: $email !== null && in_array($claims['email_verified'] ?? null, [true, 'true'], true),
            name: self::appleName($userPayload),
            isPrivateRelay: in_array($claims['is_private_email'] ?? null, [true, 'true'], true),
        );
    }

    /**
     * Payload of a JWT whose signature was ALREADY verified (by AppleAccessToken).
     * Never use this on an unverified token.
     *
     * @return array<string, mixed>
     */
    private static function jwtClaims(mixed $jwt): array
    {
        if (!is_string($jwt)) {
            throw new \UnexpectedValueException('Apple id_token missing.');
        }

        $segments = explode('.', $jwt);

        if (count($segments) !== 3) {
            throw new \UnexpectedValueException('Apple id_token is not a JWT.');
        }

        $json = base64_decode(strtr($segments[1], '-_', '+/'), true);

        try {
            $claims = json_decode((string) $json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \UnexpectedValueException('Apple id_token payload is not JSON.', previous: $exception);
        }

        if (!is_array($claims)) {
            throw new \UnexpectedValueException('Apple id_token payload is not an object.');
        }

        /** @var array<string, mixed> $claims */
        return $claims;
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
