<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OAuth2;

use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AuthorizationCodeFlowTest extends WebTestCase
{
    public function testAuthorizationRequestRedirectsToLoginWhenNotAuthenticated(): void
    {
        $browser = self::createClient();

        $browser->request(
            'GET',
            '/oauth2/authorize',
            [
                'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
                'response_type' => 'code',
                'redirect_uri' => OAuth2ClientFixture::REDIRECT_URI,
                'scope' => 'profile:read',
                'state' => 'test-state-123',
            ],
        );

        $this->assertResponseRedirects();
        $location = $browser->getResponse()->headers->get('Location');
        $this->assertIsString($location);
        $this->assertStringContainsString('/login', $location);
    }

    public function testAuthorizationRequestShowsConsentScreen(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request(
            'GET',
            '/oauth2/authorize',
            [
                'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
                'response_type' => 'code',
                'redirect_uri' => OAuth2ClientFixture::REDIRECT_URI,
                'scope' => 'profile:read',
                'state' => 'test-state-123',
            ],
        );

        $this->assertResponseIsSuccessful();

        // Check that the consent page is rendered with authorization form
        $this->assertSelectorTextContains('title', 'Authorize Confidential Test Client');
        $this->assertSelectorTextContains('body', 'Authorization Request');
        $this->assertSelectorTextContains('body', 'View your profile information');
    }

    public function testAuthorizationApprovalReturnsCode(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // First, access the authorize endpoint to get the consent screen
        $browser->request(
            'GET',
            '/oauth2/authorize',
            [
                'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
                'response_type' => 'code',
                'redirect_uri' => OAuth2ClientFixture::REDIRECT_URI,
                'scope' => 'profile:read',
                'state' => 'test-state-123',
            ],
        );

        // Submit the consent approval - OAuth2 parameters in query string, consent in POST body
        $browser->request(
            'POST',
            '/oauth2/authorize?' . http_build_query([
                'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
                'response_type' => 'code',
                'redirect_uri' => OAuth2ClientFixture::REDIRECT_URI,
                'scope' => 'profile:read',
                'state' => 'test-state-123',
            ]),
            ['consent' => 'approve'],
        );

        $this->assertResponseRedirects();

        $location = $browser->getResponse()->headers->get('Location');
        $this->assertIsString($location);
        $this->assertStringStartsWith(OAuth2ClientFixture::REDIRECT_URI, $location);
        $this->assertStringContainsString('code=', $location);
        $this->assertStringContainsString('state=test-state-123', $location);
    }

    public function testAuthorizationDenialReturnsError(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // First, access the authorize endpoint to get the consent screen
        $browser->request(
            'GET',
            '/oauth2/authorize',
            [
                'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
                'response_type' => 'code',
                'redirect_uri' => OAuth2ClientFixture::REDIRECT_URI,
                'scope' => 'profile:read',
                'state' => 'test-state-123',
            ],
        );

        // Submit the consent denial - OAuth2 parameters in query string, consent in POST body
        $browser->request(
            'POST',
            '/oauth2/authorize?' . http_build_query([
                'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
                'response_type' => 'code',
                'redirect_uri' => OAuth2ClientFixture::REDIRECT_URI,
                'scope' => 'profile:read',
                'state' => 'test-state-123',
            ]),
            ['consent' => 'deny'],
        );

        $this->assertResponseRedirects();

        $location = $browser->getResponse()->headers->get('Location');
        $this->assertIsString($location);
        $this->assertStringStartsWith(OAuth2ClientFixture::REDIRECT_URI, $location);
        $this->assertStringContainsString('error=access_denied', $location);
    }

    public function testCodeCanBeExchangedForToken(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // Get authorization code - OAuth2 parameters in query string, consent in POST body
        $browser->request(
            'POST',
            '/oauth2/authorize?' . http_build_query([
                'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
                'response_type' => 'code',
                'redirect_uri' => OAuth2ClientFixture::REDIRECT_URI,
                'scope' => 'profile:read',
                'state' => 'test-state-123',
            ]),
            ['consent' => 'approve'],
        );

        $this->assertResponseRedirects();

        $location = $browser->getResponse()->headers->get('Location');
        $this->assertIsString($location);

        $query = parse_url($location, PHP_URL_QUERY);
        $this->assertIsString($query);

        parse_str($query, $params);
        $this->assertArrayHasKey('code', $params);
        $this->assertIsString($params['code']);
        $code = $params['code'];

        // Exchange code for token
        $browser->request(
            'POST',
            '/oauth2/token',
            [
                'grant_type' => 'authorization_code',
                'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
                'client_secret' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_SECRET,
                'redirect_uri' => OAuth2ClientFixture::REDIRECT_URI,
                'code' => $code,
            ],
        );

        $this->assertResponseIsSuccessful();

        $responseContent = $browser->getResponse()->getContent();
        $this->assertIsString($responseContent);

        /** @var array<string, mixed> $response */
        $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('access_token', $response);
        $this->assertArrayHasKey('refresh_token', $response);
        $this->assertArrayHasKey('token_type', $response);
        $this->assertSame('Bearer', $response['token_type']);
    }

    public function testTokenCanBeUsedToAccessUserData(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // Get authorization code - OAuth2 parameters in query string, consent in POST body
        $browser->request(
            'POST',
            '/oauth2/authorize?' . http_build_query([
                'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
                'response_type' => 'code',
                'redirect_uri' => OAuth2ClientFixture::REDIRECT_URI,
                'scope' => 'profile:read',
                'state' => 'test-state-123',
            ]),
            ['consent' => 'approve'],
        );

        $location = $browser->getResponse()->headers->get('Location');
        $this->assertIsString($location);

        $query = parse_url($location, PHP_URL_QUERY);
        $this->assertIsString($query);

        parse_str($query, $params);
        $this->assertArrayHasKey('code', $params);
        $this->assertIsString($params['code']);
        $code = $params['code'];

        // Exchange code for token
        $browser->request(
            'POST',
            '/oauth2/token',
            [
                'grant_type' => 'authorization_code',
                'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
                'client_secret' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_SECRET,
                'redirect_uri' => OAuth2ClientFixture::REDIRECT_URI,
                'code' => $code,
            ],
        );

        $responseContent = $browser->getResponse()->getContent();
        $this->assertIsString($responseContent);

        /** @var array{access_token: string} $tokenResponse */
        $tokenResponse = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);
        $accessToken = $tokenResponse['access_token'];

        // Use token to access /me endpoint
        $browser->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $accessToken);
        $browser->request('GET', '/api/v1/me');

        $this->assertResponseIsSuccessful();

        $responseContent = $browser->getResponse()->getContent();
        $this->assertIsString($responseContent);

        /** @var array{id: string} $response */
        $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(PlayerFixture::PLAYER_REGULAR, $response['id']);
    }

    public function testExistingConsentSkipsConsentScreen(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // First authorization - shows consent screen
        $browser->request(
            'GET',
            '/oauth2/authorize',
            [
                'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
                'response_type' => 'code',
                'redirect_uri' => OAuth2ClientFixture::REDIRECT_URI,
                'scope' => 'profile:read',
                'state' => 'test-state-1',
            ],
        );

        // Approve consent - OAuth2 parameters in query string, consent in POST body
        $browser->request(
            'POST',
            '/oauth2/authorize?' . http_build_query([
                'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
                'response_type' => 'code',
                'redirect_uri' => OAuth2ClientFixture::REDIRECT_URI,
                'scope' => 'profile:read',
                'state' => 'test-state-1',
            ]),
            ['consent' => 'approve'],
        );

        $this->assertResponseRedirects();

        // Second authorization with same scopes - should skip consent
        $browser->request(
            'GET',
            '/oauth2/authorize',
            [
                'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
                'response_type' => 'code',
                'redirect_uri' => OAuth2ClientFixture::REDIRECT_URI,
                'scope' => 'profile:read',
                'state' => 'test-state-2',
            ],
        );

        // Should redirect directly with code, not show consent screen
        $this->assertResponseRedirects();

        $location = $browser->getResponse()->headers->get('Location');
        $this->assertIsString($location);
        $this->assertStringContainsString('code=', $location);
    }

    public function testNewScopeRequiresNewConsent(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // First authorization with profile:read scope - OAuth2 parameters in query string, consent in POST body
        $browser->request(
            'POST',
            '/oauth2/authorize?' . http_build_query([
                'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
                'response_type' => 'code',
                'redirect_uri' => OAuth2ClientFixture::REDIRECT_URI,
                'scope' => 'profile:read',
                'state' => 'test-state-1',
            ]),
            ['consent' => 'approve'],
        );

        $this->assertResponseRedirects();

        // Second authorization with additional scope - should show consent screen
        $browser->request(
            'GET',
            '/oauth2/authorize',
            [
                'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
                'response_type' => 'code',
                'redirect_uri' => OAuth2ClientFixture::REDIRECT_URI,
                'scope' => 'profile:read results:read',
                'state' => 'test-state-2',
            ],
        );

        // Should show consent screen (not redirect)
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'View your puzzle solving results');
    }

    public function testInvalidClientIdReturnsError(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request(
            'GET',
            '/oauth2/authorize',
            [
                'client_id' => 'invalid-client-id',
                'response_type' => 'code',
                'redirect_uri' => 'https://example.com/callback',
                'scope' => 'profile:read',
                'state' => 'test-state-123',
            ],
        );

        // Bundle returns 401 for invalid client
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testInvalidRedirectUriReturnsError(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request(
            'GET',
            '/oauth2/authorize',
            [
                'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
                'response_type' => 'code',
                'redirect_uri' => 'https://malicious-site.com/callback',
                'scope' => 'profile:read',
                'state' => 'test-state-123',
            ],
        );

        // Bundle returns 401 for invalid redirect URI
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testPublicClientRequiresPKCE(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // Request without PKCE should fail for public client
        $browser->request(
            'GET',
            '/oauth2/authorize',
            [
                'client_id' => OAuth2ClientFixture::PUBLIC_CLIENT_ID,
                'response_type' => 'code',
                'redirect_uri' => OAuth2ClientFixture::REDIRECT_URI,
                'scope' => 'profile:read',
                'state' => 'test-state-123',
            ],
        );

        // Should fail because PKCE is required for public clients
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testPublicClientWithPKCEWorks(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // Generate PKCE parameters
        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        // Request with PKCE
        $browser->request(
            'GET',
            '/oauth2/authorize',
            [
                'client_id' => OAuth2ClientFixture::PUBLIC_CLIENT_ID,
                'response_type' => 'code',
                'redirect_uri' => OAuth2ClientFixture::REDIRECT_URI,
                'scope' => 'profile:read',
                'state' => 'test-state-123',
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256',
            ],
        );

        // Should show consent screen
        $this->assertResponseIsSuccessful();
    }
}
