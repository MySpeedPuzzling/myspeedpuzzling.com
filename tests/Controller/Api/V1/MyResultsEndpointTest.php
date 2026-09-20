<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\OAuth2TestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
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

    public function testDuoResultsNameTheirPairAndSoloResultsNone(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
            PlayerFixture::PLAYER_REGULAR,
            ['results:read'],
        );
        OAuth2TestHelper::addBearerToken($browser, $token);

        /** @var Connection $database */
        $database = self::getContainer()->get(Connection::class);
        $pairId = $database->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_12]);
        self::assertIsString($pairId);

        // Fixtures: PLAYER_REGULAR + PLAYER_PRIVATE are an unnamed pair with two results
        $duo = $this->results($browser, 'duo');
        self::assertCount(2, $duo);

        foreach ($duo as $result) {
            self::assertSame($pairId, $result['team_id']);
            self::assertNull($result['team_name']);
        }

        $database->executeStatement("UPDATE puzzling_team SET name = 'Speedsters' WHERE id = :id", ['id' => $pairId]);
        self::assertSame(['Speedsters', 'Speedsters'], array_column($this->results($browser, 'duo'), 'team_name'));

        // Additive: a solo result carries the same two keys, both null - and every key it had before
        $solo = $this->results($browser, 'solo');
        self::assertNotSame([], $solo);

        foreach ($solo as $result) {
            self::assertNull($result['team_id']);
            self::assertNull($result['team_name']);

            foreach (['time_id', 'puzzle_id', 'puzzle_name', 'manufacturer_name', 'pieces_count', 'time_seconds', 'finished_at', 'first_attempt', 'puzzle_image', 'comment', 'statistics', 'difficulty'] as $key) {
                self::assertArrayHasKey($key, $result);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function results(KernelBrowser $browser, string $type): array
    {
        $browser->request('GET', '/api/v1/me/results?type=' . $type);
        $this->assertResponseIsSuccessful();

        /** @var array{type: string, results: list<array<string, mixed>>} $response */
        $response = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($type, $response['type']);

        return $response['results'];
    }
}
