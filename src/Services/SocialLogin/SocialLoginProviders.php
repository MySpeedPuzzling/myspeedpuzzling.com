<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SocialLogin;

use GuzzleHttp\ClientInterface;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Facebook;
use League\OAuth2\Client\Provider\Google;
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

    public function create(OauthProvider $provider): AbstractProvider
    {
        $redirectUri = $this->urlGenerator->generate(
            'social_login_callback',
            ['provider' => $provider->value],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $collaborators = ['httpClient' => $this->httpClient];

        return match ($provider) {
            OauthProvider::Google => new Google([
                'clientId' => $this->googleClientId,
                'clientSecret' => $this->googleClientSecret,
                'redirectUri' => $redirectUri,
                // PKCE on top of the client secret (plan: "Google: standard OIDC; use PKCE")
                'pkceMethod' => AbstractProvider::PKCE_METHOD_S256,
            ], $collaborators),
            OauthProvider::Facebook => new Facebook([
                'clientId' => $this->facebookAppId,
                'clientSecret' => $this->facebookAppSecret,
                'redirectUri' => $redirectUri,
                'graphApiVersion' => 'v23.0',
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
