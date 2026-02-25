<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V0;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GetPlayerResultsControllerTest extends WebTestCase
{
    public function testPrivatePlayerReturnsEmptyResults(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/api/v0/players/' . PlayerFixture::PLAYER_PRIVATE . '/results?token=any');

        $this->assertResponseIsSuccessful();

        $responseContent = $browser->getResponse()->getContent();
        $this->assertIsString($responseContent);

        /** @var array{solo: array<mixed>, duo: array<mixed>, team: array<mixed>} $response */
        $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([], $response['solo']);
        $this->assertSame([], $response['duo']);
        $this->assertSame([], $response['team']);
    }

    public function testPublicPlayerReturnsResults(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/api/v0/players/' . PlayerFixture::PLAYER_REGULAR . '/results?token=any');

        $this->assertResponseIsSuccessful();

        $responseContent = $browser->getResponse()->getContent();
        $this->assertIsString($responseContent);

        /** @var array<string, mixed> $response */
        $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('solo', $response);
        $this->assertArrayHasKey('duo', $response);
        $this->assertArrayHasKey('team', $response);
    }
}
