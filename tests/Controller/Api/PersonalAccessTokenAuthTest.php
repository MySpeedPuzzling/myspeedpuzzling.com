<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\PatTestHelper;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class PersonalAccessTokenAuthTest extends WebTestCase
{
    public function testPatCanAccessMeEndpoint(): void
    {
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        PatTestHelper::addBearerToken($browser, $token);

        $browser->request('GET', '/api/v1/me');

        $this->assertResponseIsSuccessful();

        $responseContent = $browser->getResponse()->getContent();
        $this->assertIsString($responseContent);

        /** @var array{id: string} $response */
        $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(PlayerFixture::PLAYER_REGULAR, $response['id']);
    }

    public function testInvalidPatReturnsUnauthorized(): void
    {
        $browser = self::createClient();

        PatTestHelper::addBearerToken($browser, 'msp_pat_invalidtokenvalue12345678901234567890123456');
        $browser->request('GET', '/api/v1/me');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testPatCanAccessMeResults(): void
    {
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        PatTestHelper::addBearerToken($browser, $token);

        $browser->request('GET', '/api/v1/me/results');

        $this->assertResponseIsSuccessful();

        $responseContent = $browser->getResponse()->getContent();
        $this->assertIsString($responseContent);

        /** @var array{player_id: string, type: string} $response */
        $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(PlayerFixture::PLAYER_REGULAR, $response['player_id']);
        $this->assertSame('solo', $response['type']);
    }

    public function testPatCanAccessMeStatistics(): void
    {
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        PatTestHelper::addBearerToken($browser, $token);

        $browser->request('GET', '/api/v1/me/statistics');

        $this->assertResponseIsSuccessful();

        $responseContent = $browser->getResponse()->getContent();
        $this->assertIsString($responseContent);

        /** @var array{player_id: string, solo: array<string, mixed>, duo: array<string, mixed>, team: array<string, mixed>} $response */
        $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(PlayerFixture::PLAYER_REGULAR, $response['player_id']);
    }

    public function testPatCanAccessMeCollections(): void
    {
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        PatTestHelper::addBearerToken($browser, $token);

        $browser->request('GET', '/api/v1/me/collections');

        $this->assertResponseIsSuccessful();
    }

    public function testPatCannotAccessPlayersEndpoints(): void
    {
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        PatTestHelper::addBearerToken($browser, $token);

        $browser->request('GET', '/api/v1/players/' . PlayerFixture::PLAYER_REGULAR . '/results');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }
}
