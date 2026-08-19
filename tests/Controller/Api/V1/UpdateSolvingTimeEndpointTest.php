<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\OAuth2TestHelper;
use SpeedPuzzling\Web\Tests\PatTestHelper;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class UpdateSolvingTimeEndpointTest extends WebTestCase
{
    public function testUpdateOwnTimeKeepsAttributionToPlayer(): void
    {
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        PatTestHelper::addBearerToken($browser, $token);

        $browser->request(
            'PUT',
            '/api/v1/me/solving-times/' . PuzzleSolvingTimeFixture::TIME_01,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'time' => '00:25:00',
                'comment' => 'Updated via API',
            ]),
        );

        $this->assertResponseIsSuccessful();

        /** @var Connection $database */
        $database = self::getContainer()->get(Connection::class);

        /** @var array{player_id: string, comment: null|string}|false $row */
        $row = $database->fetchAssociative(
            'SELECT player_id, comment FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_01],
        );

        self::assertNotFalse($row);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $row['player_id']);
        self::assertSame('Updated via API', $row['comment']);
    }

    public function testUpdateKeepsCompetitionAndRoundLink(): void
    {
        // The PUT payload carries no event information; the processor must carry the time's current
        // competition through to the handler, otherwise modify() detaches it (regression: it used to pass null).
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        PatTestHelper::addBearerToken($browser, $token);

        $browser->request(
            'PUT',
            '/api/v1/me/solving-times/' . PuzzleSolvingTimeFixture::TIME_09,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['comment' => 'Still a WJPC result']),
        );

        $this->assertResponseIsSuccessful();

        /** @var Connection $database */
        $database = self::getContainer()->get(Connection::class);

        /** @var array{competition_id: null|string, competition_round_id: null|string, comment: null|string}|false $row */
        $row = $database->fetchAssociative(
            'SELECT competition_id, competition_round_id, comment FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_09],
        );

        self::assertNotFalse($row);
        self::assertSame('Still a WJPC result', $row['comment']);
        self::assertSame(CompetitionFixture::COMPETITION_WJPC_2024, $row['competition_id']);
        self::assertSame(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, $row['competition_round_id']);
    }

    public function testUpdateForeignTimeReturnsForbidden(): void
    {
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        PatTestHelper::addBearerToken($browser, $token);

        $browser->request(
            'PUT',
            '/api/v1/me/solving-times/' . PuzzleSolvingTimeFixture::TIME_02,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['comment' => 'Hijacked']),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testUpdateUnknownTimeReturnsNotFound(): void
    {
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        PatTestHelper::addBearerToken($browser, $token);

        $browser->request(
            'PUT',
            '/api/v1/me/solving-times/00000000-0000-0000-0000-000000000000',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['comment' => 'Whatever']),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testOAuth2TokenWithWriteScopeCanUpdateOwnTime(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::WRITE_CLIENT_ID,
            PlayerFixture::PLAYER_REGULAR,
            ['solving-times:write'],
        );
        OAuth2TestHelper::addBearerToken($browser, $token);

        $browser->request(
            'PUT',
            '/api/v1/me/solving-times/' . PuzzleSolvingTimeFixture::TIME_01,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['comment' => 'Updated via OAuth2']),
        );

        $this->assertResponseIsSuccessful();

        /** @var Connection $database */
        $database = self::getContainer()->get(Connection::class);

        /** @var array{player_id: string, comment: null|string}|false $row */
        $row = $database->fetchAssociative(
            'SELECT player_id, comment FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_01],
        );

        self::assertNotFalse($row);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $row['player_id']);
        self::assertSame('Updated via OAuth2', $row['comment']);
    }

    public function testOAuth2TokenWithoutWriteScopeReturnsForbidden(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::WRITE_CLIENT_ID,
            PlayerFixture::PLAYER_REGULAR,
            ['profile:read', 'results:read'],
        );
        OAuth2TestHelper::addBearerToken($browser, $token);

        $browser->request(
            'PUT',
            '/api/v1/me/solving-times/' . PuzzleSolvingTimeFixture::TIME_01,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['comment' => 'No write scope']),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }
}
