<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OAuth2;

use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ClientCredentialsFlowTest extends WebTestCase
{
    public function testClientCredentialsGrantReturnsAccessToken(): void
    {
        $browser = self::createClient();

        $browser->request(
            'POST',
            '/oauth2/token',
            [
                'grant_type' => 'client_credentials',
                'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
                'client_secret' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_SECRET,
                'scope' => 'profile:read results:read',
            ],
        );

        $this->assertResponseIsSuccessful();

        $responseContent = $browser->getResponse()->getContent();
        $this->assertIsString($responseContent);

        /** @var array<string, mixed> $response */
        $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('access_token', $response);
        $this->assertArrayHasKey('token_type', $response);
        $this->assertArrayHasKey('expires_in', $response);
        $this->assertSame('Bearer', $response['token_type']);
        $this->assertIsInt($response['expires_in']);
        $this->assertGreaterThan(0, $response['expires_in']);
    }

    public function testClientCredentialsGrantWithInvalidClientIdFails(): void
    {
        $browser = self::createClient();

        $browser->request(
            'POST',
            '/oauth2/token',
            [
                'grant_type' => 'client_credentials',
                'client_id' => 'invalid-client-id',
                'client_secret' => 'invalid-secret',
            ],
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testClientCredentialsGrantWithInvalidSecretFails(): void
    {
        $browser = self::createClient();

        $browser->request(
            'POST',
            '/oauth2/token',
            [
                'grant_type' => 'client_credentials',
                'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
                'client_secret' => 'wrong-secret',
            ],
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testClientCredentialsGrantWithPublicClientFails(): void
    {
        $browser = self::createClient();

        // Public clients cannot use client_credentials grant
        $browser->request(
            'POST',
            '/oauth2/token',
            [
                'grant_type' => 'client_credentials',
                'client_id' => OAuth2ClientFixture::PUBLIC_CLIENT_ID,
            ],
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testClientCredentialsGrantWithUnsupportedScopeFails(): void
    {
        $browser = self::createClient();

        $browser->request(
            'POST',
            '/oauth2/token',
            [
                'grant_type' => 'client_credentials',
                'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
                'client_secret' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_SECRET,
                'scope' => 'unsupported:scope',
            ],
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testTokenCanBeUsedToAccessApi(): void
    {
        $browser = self::createClient();

        // Get token via client_credentials
        $browser->request(
            'POST',
            '/oauth2/token',
            [
                'grant_type' => 'client_credentials',
                'client_id' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
                'client_secret' => OAuth2ClientFixture::CONFIDENTIAL_CLIENT_SECRET,
                'scope' => 'statistics:read',
            ],
        );

        $this->assertResponseIsSuccessful();

        $responseContent = $browser->getResponse()->getContent();
        $this->assertIsString($responseContent);

        /** @var array{access_token: string} $tokenResponse */
        $tokenResponse = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);
        $accessToken = $tokenResponse['access_token'];

        // Use token to access API (note: client_credentials tokens have no user context)
        // This test verifies the token is valid but /me will fail without user identifier
        $browser->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $accessToken);
        $browser->request('GET', '/api/v1/players/018d0000-0000-0000-0000-000000000001/statistics');

        $this->assertResponseIsSuccessful();
    }
}
