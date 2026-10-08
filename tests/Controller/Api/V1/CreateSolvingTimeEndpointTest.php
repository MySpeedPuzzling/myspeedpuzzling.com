<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
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
     * A round of an event nobody may see - a draft, one waiting for approval - does not exist for the API either
     * (docs/features/organizations/README.md "Drafts"): 404, nothing saved
     */
    public function testRoundOfAnEventThatIsNotPublicReturnsNotFound(): void
    {
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        PatTestHelper::addBearerToken($browser, $token);

        $send = static function () use ($browser): void {
            $browser->request(
                'POST',
                '/api/v1/me/solving-times',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: (string) json_encode([
                    'puzzle_id' => PuzzleFixture::PUZZLE_3000,
                    'time' => '55:00',
                    'round_id' => OrganizationFixture::ROUND_DRAFT_NIGHT,
                ]),
            );
        };

        $send();
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // Published but waiting for approval: still not public
        $this->database()->executeStatement(
            'UPDATE competition SET is_draft = false, approved_at = NULL WHERE id = :id',
            ['id' => OrganizationFixture::COMPETITION_DRAFT_NIGHT],
        );
        $send();
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        self::assertSame(0, $this->database()->fetchOne(
            'SELECT COUNT(*) FROM puzzle_solving_time WHERE competition_round_id = :roundId',
            ['roundId' => OrganizationFixture::ROUND_DRAFT_NIGHT],
        ));

        // Approved and published, the same request saves
        $this->database()->executeStatement(
            'UPDATE competition SET approved_at = NOW() WHERE id = :id',
            ['id' => OrganizationFixture::COMPETITION_DRAFT_NIGHT],
        );
        $send();
        $this->assertResponseIsSuccessful();
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

    public function testRetryWithTheSameIdempotencyKeyAnswersTheSavedResult(): void
    {
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        PatTestHelper::addBearerToken($browser, $token);

        $send = function (string $idempotencyKey, string $comment) use ($browser): string {
            $browser->request(
                'POST',
                '/api/v1/me/solving-times',
                server: ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey],
                content: (string) json_encode([
                    'puzzle_id' => PuzzleFixture::PUZZLE_1500_02,
                    'time' => '1:02:33',
                    'comment' => $comment,
                    'finished_at' => '2026-09-20T00:00:00+00:00',
                ]),
            );

            $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);

            return (string) $browser->getResponse()->getContent();
        };

        $first = $send('retry-key-1', 'Morning');
        // The retry differs on purpose: it is still answered with what the first request saved
        $retry = $send('retry-key-1', 'Changed in the retry');

        self::assertSame($first, $retry);

        $timeId = $this->extractTimeId($first);
        /** @var array{created_via: string, comment: string} $row */
        $row = $this->database()->fetchAssociative('SELECT created_via, comment FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertSame(['created_via' => 'api', 'comment' => 'Morning'], $row);

        /** @var int|string $copies */
        $copies = $this->database()->fetchOne(
            'SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :playerId AND puzzle_id = :puzzleId AND seconds_to_solve = 3753',
            ['playerId' => PlayerFixture::PLAYER_REGULAR, 'puzzleId' => PuzzleFixture::PUZZLE_1500_02],
        );
        self::assertSame(1, (int) $copies);
        self::assertSame('resend_caught', $this->database()->fetchOne(
            "SELECT kind FROM result_duplicate_prevention WHERE time_id = :id AND via = 'api'",
            ['id' => $timeId],
        ));

        // Another key is another result
        $other = $send('retry-key-2', 'Evening');
        self::assertNotSame($timeId, $this->extractTimeId($other));
    }

    public function testAKeyReusedForAnotherResultIsRefused(): void
    {
        $browser = self::createClient();

        $token = PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR);
        PatTestHelper::addBearerToken($browser, $token);

        $send = function (string $time) use ($browser): void {
            $browser->request(
                'POST',
                '/api/v1/me/solving-times',
                server: ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'reused-key'],
                content: (string) json_encode([
                    'puzzle_id' => PuzzleFixture::PUZZLE_1500_02,
                    'time' => $time,
                    'finished_at' => '2026-09-20T00:00:00+00:00',
                ]),
            );
        };

        $send('1:02:33');
        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // The app reused the key of the previous result for a new one
        $send('1:05:00');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertResponseHeaderSame('content-type', 'application/problem+json; charset=utf-8');

        /** @var array{type: string, title: string, detail: string, status: int} $problem */
        $problem = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/errors/idempotency_key_reused', $problem['type']);
        self::assertSame(422, $problem['status']);
        self::assertStringContainsString('Idempotency-Key', $problem['detail']);

        /** @var int|string $saved */
        $saved = $this->database()->fetchOne(
            'SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :playerId AND puzzle_id = :puzzleId AND seconds_to_solve = 3900',
            ['playerId' => PlayerFixture::PLAYER_REGULAR, 'puzzleId' => PuzzleFixture::PUZZLE_1500_02],
        );
        self::assertSame(0, $saved, 'Nothing is saved');
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
