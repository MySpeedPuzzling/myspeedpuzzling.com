<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SocialLogin;

use GuzzleHttp\ClientInterface;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Facebook;
use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the league/oauth2-client provider objects (plain libraries, no bundle
 * - decision 2026-07-24). Construction is deliberately lazy (per call, only on
 * social routes): the Apple provider throws on empty credentials, and the env
 * vars stay empty until each provider console setup is done.
 *
 * All three share ONE redirect URI per provider - the login callback route.
 * Link flows (rule 5) travel through the same callback and are told apart by
 * the intent in the server-side state payload, so the provider consoles need
 * exactly one return URL each.
 */
final readonly class SocialLoginProviders
{
    public const string FACEBOOK_GRAPH_API_VERSION = 'v26.0';

    public function __construct(
        private ClientInterface $httpClient,
        private UrlGeneratorInterface $urlGenerator,
        private string $googleClientId,
        private string $googleClientSecret,
        private string $facebookAppId,
        private string $facebookAppSecret,
        private string $appleClientId,
        private string $appleTeamId,
        private string $appleKeyId,
        private string $applePrivateKey,
    ) {
    }

    /**
     * The `aud` every Apple id_token must carry - the library verifies the
     * signature only, so SocialProfileFetcher checks the audience itself.
     */
    public function appleClientId(): string
    {
        return $this->appleClientId;
    }

    /**
     * Extra query parameters for the provider's consent screen, passed to
     * getAuthorizationUrl() by both start controllers (login + connect).
     *
     * Facebook: once someone declines the `email` permission, the Login
     * Dialog never asks for it again unless told it is a re-request - and
     * without an email we cannot sign them up. `auth_type=rerequest` makes
     * every attempt ask again (Meta docs, "Manually Build a Login Flow").
     *
     * @return array<string, string>
     */
    public function authorizationOptions(OauthProvider $provider): array
    {
        return match ($provider) {
            OauthProvider::Facebook => ['auth_type' => 'rerequest'],
            default => [],
        };
    }

    public function create(OauthProvider $provider): AbstractProvider
    {
        $redirectUri = $this->urlGenerator->generate(
            'social_login_callback',
            ['provider' => $provider->value],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $collaborators = ['httpClient' => $this->httpClient];

        return match ($provider) {
            // PKCE (S256) on top of the client secret - the subclass is what
            // turns it on, the league Google provider ignores a pkceMethod option
            OauthProvider::Google => new GoogleProviderWithPkce([
                'clientId' => $this->googleClientId,
                'clientSecret' => $this->googleClientSecret,
                'redirectUri' => $redirectUri,
            ], $collaborators),
            OauthProvider::Facebook => new Facebook([
                'clientId' => $this->facebookAppId,
                'clientSecret' => $this->facebookAppSecret,
                'redirectUri' => $redirectUri,
                // Newest Graph version (released 2026-07-29). Meta keeps every
                // version for at least two years, so this one lives until
                // July 2028 at the earliest (v25.0: until 2028-07-29). Bump
                // before expiry: https://developers.facebook.com/docs/graph-api/changelog/versions/
                'graphApiVersion' => self::FACEBOOK_GRAPH_API_VERSION,
            ], $collaborators),
            OauthProvider::Apple => new AppleProviderWithInlineKey([
                'clientId' => $this->appleClientId,
                'teamId' => $this->appleTeamId,
                'keyFileId' => $this->appleKeyId,
                'keyContents' => $this->applePrivateKey,
                'redirectUri' => $redirectUri,
            ], $collaborators),
        };
    }
}
