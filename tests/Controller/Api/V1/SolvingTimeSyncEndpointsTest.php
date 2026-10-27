<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\PatTestHelper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * DELETE /me/solving-times/{id}, POST /me/solving-times/lookup and GET /me/solving-times/deleted
 * (docs/features/api/solving-time-sync.md).
 */
final class SolvingTimeSyncEndpointsTest extends WebTestCase
{
    private const string UNKNOWN_TIME = '018d0006-ffff-0000-0000-000000000001';

    public function testTrackerDeletesTheirTimeIntoTheGraveyard(): void
    {
        $browser = self::browserAs(PlayerFixture::PLAYER_REGULAR);

        $browser->request('DELETE', '/api/v1/me/solving-times/' . PuzzleSolvingTimeFixture::TIME_01);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $database = self::database();
        self::assertSame(0, self::rowCount($database, 'SELECT COUNT(*) FROM puzzle_solving_time WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_01]));

        /** @var array{player_id: string, deleted_via: string, snapshot: string}|false $grave */
        $grave = $database->fetchAssociative(
            'SELECT player_id, deleted_via, snapshot FROM solving_time_graveyard WHERE solving_time_id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_01],
        );
        self::assertNotFalse($grave);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $grave['player_id']);
        self::assertSame('api', $grave['deleted_via']);

        /** @var array{id: string, seconds_to_solve: int} $snapshot */
        $snapshot = json_decode($grave['snapshot'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(PuzzleSolvingTimeFixture::TIME_01, $snapshot['id']);
        self::assertSame(1800, $snapshot['seconds_to_solve']);

        self::assertSame([PlayerFixture::PLAYER_REGULAR], $database->fetchFirstColumn(
            'SELECT player_id FROM solving_time_tombstone WHERE solving_time_id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_01],
        ));

        // Gone = 404 from now on, safe to retry
        $browser->request('DELETE', '/api/v1/me/solving-times/' . PuzzleSolvingTimeFixture::TIME_01);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testSomebodyElsesTimeCannotBeDeleted(): void
    {
        $browser = self::browserAs(PlayerFixture::PLAYER_REGULAR);

        $browser->request('DELETE', '/api/v1/me/solving-times/' . PuzzleSolvingTimeFixture::TIME_02);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(1, self::rowCount(self::database(), 'SELECT COUNT(*) FROM puzzle_solving_time WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_02]));
    }

    public function testAPairMemberWhoDidNotTrackTheTimeCannotDeleteIt(): void
    {
        // TIME_12: tracked by PLAYER_REGULAR, PLAYER_PRIVATE is the other member
        $browser = self::browserAs(PlayerFixture::PLAYER_PRIVATE);

        $browser->request('DELETE', '/api/v1/me/solving-times/' . PuzzleSolvingTimeFixture::TIME_12);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testUnknownTimeIsNotFound(): void
    {
        $browser = self::browserAs(PlayerFixture::PLAYER_REGULAR);

        $browser->request('DELETE', '/api/v1/me/solving-times/' . self::UNKNOWN_TIME);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testDeletingAPairTimeTellsEveryMember(): void
    {
        $browser = self::browserAs(PlayerFixture::PLAYER_REGULAR);
        $browser->request('DELETE', '/api/v1/me/solving-times/' . PuzzleSolvingTimeFixture::TIME_12);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        // The other member's client learns about it
        $browser = self::browserAs(PlayerFixture::PLAYER_PRIVATE, $browser);
        $browser->request('GET', '/api/v1/me/solving-times/deleted');

        self::assertResponseIsSuccessful();
        $response = self::json($browser);
        self::assertSame(1, $response['count']);
        /** @var list<array{time_id: string}> $deleted */
        $deleted = $response['deleted'];
        self::assertSame(PuzzleSolvingTimeFixture::TIME_12, $deleted[0]['time_id']);
        self::assertNull($response['next_cursor']);
    }

    public function testLookupSortsEveryIdIntoFoundDeletedOrMissing(): void
    {
        $browser = self::browserAs(PlayerFixture::PLAYER_REGULAR);
        $browser->request('DELETE', '/api/v1/me/solving-times/' . PuzzleSolvingTimeFixture::TIME_01);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $browser = self::browserAs(PlayerFixture::PLAYER_PRIVATE, $browser);
        $browser->request('POST', '/api/v1/me/solving-times/lookup', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'time_ids' => [
                PuzzleSolvingTimeFixture::TIME_02, // own solo
                strtoupper(PuzzleSolvingTimeFixture::TIME_12), // a pair time somebody else tracked, upper case
                PuzzleSolvingTimeFixture::TIME_12, // the same again
                PuzzleSolvingTimeFixture::TIME_03, // somebody else's
                self::UNKNOWN_TIME,
            ],
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $response = self::json($browser);

        /** @var list<array{time_id: string, type: string, tracked_by_me: bool, puzzle_id: string}> $found */
        $found = $response['found'];
        $foundById = array_column($found, null, 'time_id');
        ksort($foundById);
        self::assertSame([PuzzleSolvingTimeFixture::TIME_02, PuzzleSolvingTimeFixture::TIME_12], array_keys($foundById));
        self::assertSame('solo', $foundById[PuzzleSolvingTimeFixture::TIME_02]['type']);
        self::assertTrue($foundById[PuzzleSolvingTimeFixture::TIME_02]['tracked_by_me']);
        self::assertSame('duo', $foundById[PuzzleSolvingTimeFixture::TIME_12]['type']);
        self::assertFalse($foundById[PuzzleSolvingTimeFixture::TIME_12]['tracked_by_me']);

        self::assertSame([], $response['deleted']);
        $missing = $response['missing'];
        self::assertIsArray($missing);
        sort($missing);
        self::assertSame([PuzzleSolvingTimeFixture::TIME_03, self::UNKNOWN_TIME], $missing);

        // The tracker of TIME_01 sees it as deleted, with the moment
        $browser = self::browserAs(PlayerFixture::PLAYER_REGULAR, $browser);
        $browser->request('POST', '/api/v1/me/solving-times/lookup', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'time_ids' => [PuzzleSolvingTimeFixture::TIME_01],
        ]));

        $response = self::json($browser);
        self::assertSame([], $response['found']);
        self::assertSame([], $response['missing']);
        /** @var list<array{time_id: string, deleted_at: string}> $deleted */
        $deleted = $response['deleted'];
        self::assertSame(PuzzleSolvingTimeFixture::TIME_01, $deleted[0]['time_id']);
        self::assertNotSame('', $deleted[0]['deleted_at']);
    }

    public function testLookupRefusesTooManyOrInvalidIds(): void
    {
        $browser = self::browserAs(PlayerFixture::PLAYER_REGULAR);

        $tooMany = array_map(static fn (): string => Uuid::uuid7()->toString(), range(1, 501));

        foreach ([[], $tooMany, ['not-a-uuid'], ['{' . self::UNKNOWN_TIME . '}']] as $timeIds) {
            $browser->request('POST', '/api/v1/me/solving-times/lookup', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
                'time_ids' => $timeIds,
            ]));

            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $fiveHundred = array_slice($tooMany, 0, 500);
        $browser->request('POST', '/api/v1/me/solving-times/lookup', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'time_ids' => $fiveHundred,
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $missing = self::json($browser)['missing'];
        self::assertIsArray($missing);
        self::assertCount(500, $missing);
    }

    public function testDeletedListPagesThroughEveryTombstoneOnce(): void
    {
        $browser = self::browserAs(PlayerFixture::PLAYER_WITH_STRIPE);

        // 1003 tombstones, many sharing a second - the cursor must not skip or repeat any
        self::database()->executeStatement(<<<SQL
INSERT INTO solving_time_tombstone (id, solving_time_id, player_id, deleted_at)
SELECT gen_random_uuid(), gen_random_uuid(), :playerId, TIMESTAMP '2026-10-27 10:00:00' + (n / 7) * INTERVAL '1 second'
FROM generate_series(1, 1003) AS n
SQL, ['playerId' => PlayerFixture::PLAYER_WITH_STRIPE]);

        $browser->request('GET', '/api/v1/me/solving-times/deleted');

        self::assertResponseIsSuccessful();
        $first = self::json($browser);
        self::assertSame(1000, $first['count']);
        self::assertIsString($first['next_cursor']);

        $browser->request('GET', '/api/v1/me/solving-times/deleted', ['cursor' => $first['next_cursor']]);
        $second = self::json($browser);
        self::assertSame(3, $second['count']);
        self::assertNull($second['next_cursor']);

        /** @var list<array{time_id: string}> $firstDeleted */
        $firstDeleted = $first['deleted'];
        /** @var list<array{time_id: string}> $secondDeleted */
        $secondDeleted = $second['deleted'];
        $ids = array_column([...$firstDeleted, ...$secondDeleted], 'time_id');
        self::assertCount(1003, array_unique($ids));

        // since: only the later ones (n / 7 >= 140 → n >= 980)
        $browser->request('GET', '/api/v1/me/solving-times/deleted', ['since' => '2026-10-27T12:02:20+02:00']);
        self::assertSame(1003 - 979, self::json($browser)['count']);

        $browser->request('GET', '/api/v1/me/solving-times/deleted', ['since' => 'yesterday-ish']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $browser->request('GET', '/api/v1/me/solving-times/deleted', ['cursor' => 'garbage']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private static function browserAs(string $playerId, null|KernelBrowser $browser = null): KernelBrowser
    {
        $browser ??= self::createClient();
        $browser->setServerParameters([]);
        PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, $playerId));

        return $browser;
    }

    /**
     * @param array<string, string> $parameters
     */
    private static function rowCount(Connection $database, string $sql, array $parameters = []): int
    {
        $value = $database->fetchOne($sql, $parameters);
        assert(is_int($value) || is_string($value));

        return (int) $value;
    }

    private static function database(): Connection
    {
        /** @var Connection $database */
        $database = self::getContainer()->get(Connection::class);

        return $database;
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(KernelBrowser $browser): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
