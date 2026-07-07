<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\PatTestHelper;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class CreateSolvingTimeEndpointTest extends WebTestCase
{
    public function testWithoutTokenReturnsUnauthorized(): void
    {
        $browser = self::createClient();

        $browser->request(
            'POST',
            '/api/v1/me/solving-times',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'puzzle_id' => PuzzleFixture::PUZZLE_500_01,
                'time' => '10:00',
            ]),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testCreateWithoutRoundId(): void
    {
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        PatTestHelper::addBearerToken($browser, $token);

        $browser->request(
            'POST',
            '/api/v1/me/solving-times',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'puzzle_id' => PuzzleFixture::PUZZLE_500_01,
                'time' => '10:00',
            ]),
        );

        $this->assertResponseIsSuccessful();

        $timeId = $this->extractTimeId($browser->getResponse()->getContent());

        /** @var array{competition_round_id: null|string, player_id: string}|false $row */
        $row = $this->database()->fetchAssociative(
            'SELECT competition_round_id, player_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId],
        );

        self::assertNotFalse($row);
        self::assertNull($row['competition_round_id']);
        // Guards against the time being attributed to a phantom player created from the player uuid
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $row['player_id']);
    }

    public function testCreateWithValidRoundIdLinksRoundAndCompetition(): void
    {
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        PatTestHelper::addBearerToken($browser, $token);

        $browser->request(
            'POST',
            '/api/v1/me/solving-times',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'puzzle_id' => PuzzleFixture::PUZZLE_500_01,
                'time' => '10:00',
                'round_id' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
            ]),
        );

        $this->assertResponseIsSuccessful();

        $timeId = $this->extractTimeId($browser->getResponse()->getContent());

        /** @var array{competition_round_id: null|string, competition_id: null|string}|false $row */
        $row = $this->database()->fetchAssociative(
            'SELECT competition_round_id, competition_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId],
        );

        self::assertNotFalse($row);
        self::assertSame(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, $row['competition_round_id']);
        self::assertSame(CompetitionFixture::COMPETITION_WJPC_2024, $row['competition_id']);
    }

    public function testInvalidRoundIdReturnsNotFound(): void
    {
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        PatTestHelper::addBearerToken($browser, $token);

        $browser->request(
            'POST',
            '/api/v1/me/solving-times',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'puzzle_id' => PuzzleFixture::PUZZLE_500_01,
                'time' => '10:00',
                'round_id' => '00000000-0000-0000-0000-000000000000',
            ]),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function extractTimeId(string|false $responseContent): string
    {
        self::assertIsString($responseContent);

        /** @var array{time_id: string} $response */
        $response = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

        return $response['time_id'];
    }
}
