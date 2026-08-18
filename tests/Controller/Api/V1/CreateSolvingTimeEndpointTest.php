<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\OAuth2TestHelper;
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

    /**
     * Pins the role name to what the bundle derives from the scope
     * (ROLE_OAUTH2_SOLVING-TIMES:WRITE, hyphen kept). The check used to spell it
     * with an underscore, so no OAuth2 token could ever write - and the PAT-only
     * tests above never noticed, because ROLE_PAT short-circuits the "or".
     */
    public function testOAuth2TokenWithWriteScopeCanCreate(): void
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

        /** @var array{player_id: string}|false $row */
        $row = $this->database()->fetchAssociative(
            'SELECT player_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId],
        );

        self::assertNotFalse($row);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $row['player_id']);
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
            'POST',
            '/api/v1/me/solving-times',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'puzzle_id' => PuzzleFixture::PUZZLE_500_01,
                'time' => '10:00',
            ]),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * A client_credentials token has no user behind it, so it must never reach
     * the processor (which asserts an ApiUser and would 500) - even when it
     * somehow carries the write scope.
     */
    public function testClientCredentialsTokenReturnsForbidden(): void
    {
        $browser = self::createClient();

        $token = OAuth2TestHelper::createAccessToken(
            $browser,
            OAuth2ClientFixture::WRITE_CLIENT_ID,
            OAuth2ClientFixture::WRITE_CLIENT_ID,
            ['solving-times:write'],
        );
        OAuth2TestHelper::addBearerToken($browser, $token);

        $browser->request(
            'POST',
            '/api/v1/me/solving-times',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'puzzle_id' => PuzzleFixture::PUZZLE_500_01,
                'time' => '10:00',
            ]),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
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
