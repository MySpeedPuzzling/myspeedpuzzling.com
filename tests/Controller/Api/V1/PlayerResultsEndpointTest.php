<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OAuth2TestHelper;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class PlayerResultsEndpointTest extends WebTestCase
{
    public function testWithValidToken(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            PlayerFixture::PLAYER_REGULAR,
            ['results:read'],
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
        $browser->request('GET', '/api/v1/players/' . PlayerFixture::PLAYER_REGULAR . '/results');

        $this->assertResponseIsSuccessful();

        $responseContent = $browser->getResponse()->getContent();
        $this->assertIsString($responseContent);

        /** @var array<string, mixed> $response */
        $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(PlayerFixture::PLAYER_REGULAR, $response['player_id']);
        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('count', $response);
        $this->assertArrayHasKey('results', $response);
    }

    public function testWithoutRequiredScopeReturnsForbidden(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            PlayerFixture::PLAYER_REGULAR,
            ['profile:read'], // Wrong scope
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
        $browser->request('GET', '/api/v1/players/' . PlayerFixture::PLAYER_REGULAR . '/results');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testNonExistentPlayerReturnsNotFound(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            PlayerFixture::PLAYER_REGULAR,
            ['results:read'],
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
        $browser->request('GET', '/api/v1/players/00000000-0000-0000-0000-000000000000/results');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testPrivatePlayerReturnsEmptyResults(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            PlayerFixture::PLAYER_REGULAR,
            ['results:read'],
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
        $browser->request('GET', '/api/v1/players/' . PlayerFixture::PLAYER_PRIVATE . '/results');

        $this->assertResponseIsSuccessful();

        $responseContent = $browser->getResponse()->getContent();
        $this->assertIsString($responseContent);

        /** @var array{player_id: string, count: int, results: array<mixed>} $response */
        $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(PlayerFixture::PLAYER_PRIVATE, $response['player_id']);
        $this->assertSame(0, $response['count']);
        $this->assertSame([], $response['results']);
    }

    public function testPrivatePlayerReturnsEmptyForAllTypes(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            PlayerFixture::PLAYER_REGULAR,
            ['results:read'],
        );

        OAuth2TestHelper::addBearerToken($browser, $token);

        foreach (['solo', 'duo', 'team'] as $type) {
            $browser->request('GET', '/api/v1/players/' . PlayerFixture::PLAYER_PRIVATE . '/results?type=' . $type);

            $this->assertResponseIsSuccessful();

            $responseContent = $browser->getResponse()->getContent();
            $this->assertIsString($responseContent);

            /** @var array{count: int, results: array<mixed>, type: string} $response */
            $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame($type, $response['type']);
            $this->assertSame(0, $response['count']);
            $this->assertSame([], $response['results']);
        }
    }

    public function testWithTypeParameter(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            PlayerFixture::PLAYER_REGULAR,
            ['results:read'],
        );

        OAuth2TestHelper::addBearerToken($browser, $token);

        foreach (['solo', 'duo', 'team'] as $type) {
            $browser->request('GET', '/api/v1/players/' . PlayerFixture::PLAYER_REGULAR . '/results?type=' . $type);
            $this->assertResponseIsSuccessful();

            $responseContent = $browser->getResponse()->getContent();
            $this->assertIsString($responseContent);

            /** @var array{type: string} $response */
            $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($type, $response['type']);
        }
    }
}
