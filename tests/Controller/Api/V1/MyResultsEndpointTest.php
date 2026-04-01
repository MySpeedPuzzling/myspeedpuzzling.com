<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OAuth2TestHelper;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class MyResultsEndpointTest extends WebTestCase
{
    public function testWithoutTokenReturnsUnauthorized(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/api/v1/me/results');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testWithValidTokenReturnsSoloResults(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            PlayerFixture::PLAYER_REGULAR,
            ['results:read'],
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
        $browser->request('GET', '/api/v1/me/results');

        $this->assertResponseIsSuccessful();

        $responseContent = $browser->getResponse()->getContent();
        $this->assertIsString($responseContent);

        /** @var array{player_id: string, type: string, count: int, results: array<mixed>} $response */
        $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(PlayerFixture::PLAYER_REGULAR, $response['player_id']);
        $this->assertSame('solo', $response['type']);
    }

    public function testTypeQueryParameter(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            PlayerFixture::PLAYER_REGULAR,
            ['results:read'],
        );

        OAuth2TestHelper::addBearerToken($browser, $token);
        $browser->request('GET', '/api/v1/me/results?type=duo');

        $this->assertResponseIsSuccessful();

        $responseContent = $browser->getResponse()->getContent();
        $this->assertIsString($responseContent);

        /** @var array{type: string} $response */
        $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('duo', $response['type']);
    }
}
