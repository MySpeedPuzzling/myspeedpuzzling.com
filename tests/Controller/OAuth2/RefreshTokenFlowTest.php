<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OAuth2;

use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class RefreshTokenFlowTest extends WebTestCase
{
    public function testRefreshTokenGrantReturnsNewAccessToken(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // Get tokens through proper authorization flow
        $tokens = $this->getTokensViaAuthorizationFlow($browser);

        // Attempt to refresh
        $browser->request(
            'POST',
            '/oauth2/token',
            [
                'grant_type' => 'refresh_token',
                'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
                'client_secret' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_SECRET,
                'refresh_token' => $tokens['refresh_token'],
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
        $this->assertArrayHasKey('expires_in', $response);
        $this->assertSame('Bearer', $response['token_type']);
    }

    public function testRefreshTokenGrantWithInvalidTokenFails(): void
    {
        $browser = self::createClient();

        $browser->request(
            'POST',
            '/oauth2/token',
            [
                'grant_type' => 'refresh_token',
                'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
                'client_secret' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_SECRET,
                'refresh_token' => 'invalid-refresh-token',
            ],
        );

        // Invalid token returns 400 Bad Request, not 401
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testRefreshTokenGrantWithWrongClientFails(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // Get tokens for confidential client
        $tokens = $this->getTokensViaAuthorizationFlow($browser);

        // Try to use refresh token with first-party client (different client)
        $browser->request(
            'POST',
            '/oauth2/token',
            [
                'grant_type' => 'refresh_token',
                'client_id' => OAuth2ClientFixture::FIRST_PARTY_CLIENT_ID,
                'client_secret' => 'first-party-secret-12345678901234567890',
                'refresh_token' => $tokens['refresh_token'],
            ],
        );

        // Wrong client returns 400 Bad Request (invalid_grant per OAuth2 spec)
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testNewAccessTokenWorksAfterRefresh(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // Get tokens with statistics:read scope
        $tokens = $this->getTokensViaAuthorizationFlow($browser, 'statistics:read');

        // Refresh the token
        $browser->request(
            'POST',
            '/oauth2/token',
            [
                'grant_type' => 'refresh_token',
                'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
                'client_secret' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_SECRET,
                'refresh_token' => $tokens['refresh_token'],
            ],
        );

        $this->assertResponseIsSuccessful();

        $responseContent = $browser->getResponse()->getContent();
        $this->assertIsString($responseContent);

        /** @var array{access_token: string} $tokenResponse */
        $tokenResponse = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);
        $newAccessToken = $tokenResponse['access_token'];

        // Use the new access token
        $browser->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $newAccessToken);
        $browser->request('GET', '/api/v1/players/' . PlayerFixture::PLAYER_REGULAR . '/statistics');

        $this->assertResponseIsSuccessful();
    }

    /**
     * @return array{access_token: string, refresh_token: string}
     */
    private function getTokensViaAuthorizationFlow(
        \Symfony\Bundle\FrameworkBundle\KernelBrowser $browser,
        string $scope = 'profile:read',
    ): array {
        // Authorize and approve consent
        $browser->request(
            'POST',
            '/oauth2/authorize?' . http_build_query([
                'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
                'response_type' => 'code',
                'redirect_uri' => OAuth2ClientFixture::REDIRECT_URI,
                'scope' => $scope,
                'state' => 'test-state-' . bin2hex(random_bytes(8)),
            ]),
            ['consent' => 'approve'],
        );

        $location = $browser->getResponse()->headers->get('Location');
        assert(is_string($location));

        $query = parse_url($location, PHP_URL_QUERY);
        assert(is_string($query));

        parse_str($query, $params);
        assert(isset($params['code']) && is_string($params['code']));
        $code = $params['code'];

        // Exchange code for tokens
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
        assert(is_string($responseContent));

        /** @var array{access_token: string, refresh_token: string} $response */
        $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

        return $response;
    }
}
