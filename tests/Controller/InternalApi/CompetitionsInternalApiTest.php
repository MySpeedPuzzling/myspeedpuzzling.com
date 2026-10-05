<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use Doctrine\DBAL\Connection;
use Monolog\Handler\TestHandler;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\TagFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CompetitionsInternalApiTest extends WebTestCase
{
    use InternalApiRequests;

    public function testWithoutTokenEveryEndpointIsUnauthorizedAsJson(): void
    {
        $browser = self::createClient();

        $endpoints = [
            ['GET', '/internal-api/competitions'],
            ['GET', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024],
            ['POST', '/internal-api/competitions'],
            ['PATCH', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024],
            ['POST', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_UNAPPROVED . '/approve'],
            ['PUT', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024 . '/puzzles'],
            ['POST', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024 . '/rounds'],
            ['PATCH', '/internal-api/rounds/' . CompetitionRoundFixture::ROUND_WJPC_FINAL],
            ['DELETE', '/internal-api/rounds/' . CompetitionRoundFixture::ROUND_WJPC_FINAL],
            ['PUT', '/internal-api/rounds/' . CompetitionRoundFixture::ROUND_WJPC_FINAL . '/puzzles'],
            ['GET', '/internal-api/puzzles?q=puzzle'],
            ['POST', '/internal-api/puzzles'],
        ];

        foreach ($endpoints as [$method, $uri]) {
            $answer = self::callInternalApi($browser, $method, $uri, ['name' => 'x'], token: null);

            self::assertResponseStatusCodeSame(401, $method . ' ' . $uri);
            self::assertIsString($answer['error'] ?? null, $method . ' ' . $uri);
        }
    }

    public function testAWrongTokenIsUnauthorized(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'GET', '/internal-api/competitions', token: 'not-the-token');

        self::assertResponseStatusCodeSame(401);
        self::assertSame('Invalid internal API token.', $answer['error']);
    }

    public function testListsEveryCompetitionIncludingUnapprovedOnes(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'GET', '/internal-api/competitions?limit=100');

        self::assertResponseIsSuccessful();
        $ids = array_column(self::list($answer['competitions']), 'competitionId');
        self::assertContains(CompetitionFixture::COMPETITION_WJPC_2024, $ids);
        self::assertContains(CompetitionFixture::COMPETITION_UNAPPROVED, $ids);
        // Editions of a series are competitions too
        self::assertContains(CompetitionSeriesFixture::EDITION_EJJ_68, $ids);
    }

    public function testSearchesByNameSlugOrShortcutAndFiltersByStatus(): void
    {
        $browser = self::createClient();

        $byName = self::callInternalApi($browser, 'GET', '/internal-api/competitions?q=unapproved%20puzzle');
        self::assertSame([CompetitionFixture::COMPETITION_UNAPPROVED], array_column(self::list($byName['competitions']), 'competitionId'));
        self::assertSame('pending', self::list($byName['competitions'])[0]['status']);

        $byShortcut = self::callInternalApi($browser, 'GET', '/internal-api/competitions?q=WJPC24');
        self::assertSame([CompetitionFixture::COMPETITION_WJPC_2024], array_column(self::list($byShortcut['competitions']), 'competitionId'));

        $pending = self::callInternalApi($browser, 'GET', '/internal-api/competitions?status=pending&limit=100');
        $pendingIds = array_column(self::list($pending['competitions']), 'competitionId');
        self::assertContains(CompetitionFixture::COMPETITION_UNAPPROVED, $pendingIds);
        self::assertNotContains(CompetitionFixture::COMPETITION_WJPC_2024, $pendingIds);

        self::callInternalApi($browser, 'GET', '/internal-api/competitions?status=nonsense');
        self::assertResponseStatusCodeSame(400);
    }

    public function testDetailHoldsEveryEditableFieldRoundsPuzzlesAndMaintainers(): void
    {
        $browser = self::createClient();

        $byId = self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024);

        self::assertResponseIsSuccessful();
        self::assertSame('WJPC 2024', $byId['name']);
        self::assertSame('wjpc-2024', $byId['slug']);
        self::assertSame('WJPC24', $byId['shortcut']);
        self::assertSame('Prague', $byId['location']);
        self::assertSame('cz', $byId['locationCountryCode']);
        self::assertSame('https://wjpc2024.com', $byId['link']);
        self::assertSame('https://wjpc2024.com/register', $byId['registrationLink']);
        self::assertSame('https://wjpc2024.com/results', $byId['resultsLink']);
        self::assertFalse($byId['isOnline']);
        self::assertFalse($byId['isRecurring']);
        self::assertSame('approved', $byId['status']);
        self::assertTrue($byId['publiclyVisible']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) self::string($byId['dateFrom']));
        self::assertSame(TagFixture::TAG_WJPC, $byId['tagId']);
        self::assertSame([], $byId['puzzles']);

        $rounds = self::list($byId['rounds']);
        self::assertSame(
            [CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, CompetitionRoundFixture::ROUND_WJPC_FINAL],
            array_column($rounds, 'roundId'),
        );
        self::assertSame('solo', $rounds[0]['category']);
        self::assertSame(60, $rounds[0]['minutesLimit']);
        self::assertSame(3, $rounds[0]['resultsCount']);
        self::assertEqualsCanonicalizing(
            [PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02],
            array_column(self::list($rounds[0]['puzzles']), 'puzzleId'),
        );

        $bySlug = self::callInternalApi($browser, 'GET', '/internal-api/competitions/wjpc-2024');
        self::assertSame(CompetitionFixture::COMPETITION_WJPC_2024, $bySlug['competitionId']);

        $unapproved = self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_UNAPPROVED);
        self::assertSame([PlayerFixture::PLAYER_REGULAR], array_column(self::list($unapproved['maintainers']), 'playerId'));
        self::assertFalse($unapproved['publiclyVisible']);
    }

    public function testUnknownCompetitionIsNotFound(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'GET', '/internal-api/competitions/no-such-event');
        self::assertResponseStatusCodeSame(404);
        self::assertArrayHasKey('error', $answer);

        self::callInternalApi($browser, 'GET', '/internal-api/competitions/018d0004-0000-0000-0000-00000000ffff');
        self::assertResponseStatusCodeSame(404);

        self::callInternalApi($browser, 'PATCH', '/internal-api/competitions/018d0004-0000-0000-0000-00000000ffff', ['name' => 'X']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCreatesAnApprovedCompetition(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'POST', '/internal-api/competitions', [
            'name' => 'Ostrava Puzzle Open 2026',
            'shortcut' => 'OPO26',
            'location' => 'Ostrava',
            'locationCountryCode' => 'CZ',
            'dateFrom' => '2026-11-14',
            'dateTo' => '2026-11-15',
            'link' => 'https://example.com/opo',
            'registrationLink' => 'https://example.com/opo/register',
            'approve' => true,
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Ostrava Puzzle Open 2026', $answer['name']);
        self::assertSame('ostrava-puzzle-open-2026', $answer['slug']);
        self::assertSame('cz', $answer['locationCountryCode']);
        self::assertSame('2026-11-14', $answer['dateFrom']);
        self::assertSame('2026-11-15', $answer['dateTo']);
        self::assertSame('approved', $answer['status']);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $answer['addedByPlayerId']);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $answer['approvedByPlayerId']);
        self::assertTrue($answer['publiclyVisible']);

        // Nobody is e-mailed: an admin added it, and approved it himself
        self::assertQueuedEmailCount(0);
    }

    public function testCreatedCompetitionStaysPendingWithoutApprove(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'POST', '/internal-api/competitions', [
            'name' => 'Online Speed Cup',
            'isOnline' => true,
            'slug' => 'online-speed-cup-autumn',
            'maintainerIds' => [PlayerFixture::PLAYER_REGULAR],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('online-speed-cup-autumn', $answer['slug']);
        self::assertSame('pending', $answer['status']);
        self::assertTrue($answer['isOnline']);
        self::assertSame([PlayerFixture::PLAYER_REGULAR], array_column(self::list($answer['maintainers']), 'playerId'));
    }

    public function testInvalidCompetitionIsRefusedWithFieldErrors(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'POST', '/internal-api/competitions', [
            'shortcut' => 'X',
            'dateFrom' => '2026-13-01',
            'link' => 'not a url',
            'locationCountryCode' => 'xx',
            'website' => 'https://example.com',
            'slug' => 'Not A Slug',
        ]);

        self::assertResponseStatusCodeSame(400);
        $errors = $answer['errors'];
        self::assertIsArray($errors);
        foreach (['name', 'location', 'dateFrom', 'dateTo', 'link', 'locationCountryCode', 'website', 'slug'] as $field) {
            self::assertArrayHasKey($field, $errors, $field);
        }
    }

    public function testDatesMustBeInOrder(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'POST', '/internal-api/competitions', [
            'name' => 'Backwards Event',
            'location' => 'Brno',
            'dateFrom' => '2026-11-15',
            'dateTo' => '2026-11-14',
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertIsArray($answer['errors']);
        self::assertArrayHasKey('dateTo', $answer['errors']);
    }

    public function testMalformedJsonIsBadRequest(): void
    {
        $browser = self::createClient();
        $browser->request('POST', '/internal-api/competitions', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::INTERNAL_API_TOKEN,
            'CONTENT_TYPE' => 'application/json',
        ], content: '{"name": ');

        self::assertResponseStatusCodeSame(400);
        self::assertJson((string) $browser->getResponse()->getContent());
    }

    public function testATakenSlugIsAConflict(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'POST', '/internal-api/competitions', [
            'name' => 'Another WJPC',
            'isOnline' => true,
            'slug' => 'wjpc-2024',
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('wjpc-2024', self::string($answer['error']));

        self::callInternalApi($browser, 'PATCH', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_UNAPPROVED, [
            'slug' => 'czech-nationals-2024',
        ]);
        self::assertResponseStatusCodeSame(409);

        $unchanged = self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_UNAPPROVED);
        self::assertSame('unapproved-puzzle-event', $unchanged['slug']);
    }

    public function testRenamingKeepsTheSlug(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'PATCH', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024, [
            'name' => 'World Jigsaw Puzzle Championship 2024',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('World Jigsaw Puzzle Championship 2024', $answer['name']);
        self::assertSame('wjpc-2024', $answer['slug']);
        // Fields not sent stay as they were
        self::assertSame('WJPC24', $answer['shortcut']);
        self::assertSame('Prague', $answer['location']);
        self::assertSame('https://wjpc2024.com/results', $answer['resultsLink']);
        self::assertSame('approved', $answer['status']);
    }

    public function testAnExplicitSlugChangesIt(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'PATCH', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024, [
            'name' => 'WJPC 2024 Prague',
            'slug' => 'wjpc-2024-prague',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('wjpc-2024-prague', $answer['slug']);

        self::callInternalApi($browser, 'GET', '/internal-api/competitions/wjpc-2024-prague');
        self::assertResponseIsSuccessful();

        self::callInternalApi($browser, 'PATCH', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024, [
            'slug' => 'Bad Slug!',
        ]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testUpdatesLinksLocationAndDates(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'PATCH', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, [
            'link' => 'https://example.com/nationals',
            'registrationLink' => 'https://example.com/nationals/register',
            'resultsLink' => 'https://example.com/nationals/results',
            'location' => 'Ostrava',
            'locationCountryCode' => 'cz',
            'dateFrom' => '2026-12-05',
            'dateTo' => '2026-12-06T18:00:00+01:00',
            'description' => null,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('https://example.com/nationals', $answer['link']);
        self::assertSame('https://example.com/nationals/register', $answer['registrationLink']);
        self::assertSame('https://example.com/nationals/results', $answer['resultsLink']);
        self::assertSame('Ostrava', $answer['location']);
        self::assertSame('2026-12-05', $answer['dateFrom']);
        self::assertSame('2026-12-06', $answer['dateTo']);
        self::assertNull($answer['description']);
        self::assertSame('czech-nationals-2024', $answer['slug']);
    }

    public function testUpdatesTheLinksOfAnEditionOfASeries(): void
    {
        $browser = self::createClient();

        // An in-person edition: its place belongs to the series, its dates are optional
        $answer = self::callInternalApi($browser, 'PATCH', '/internal-api/competitions/' . CompetitionSeriesFixture::EDITION_OFFLINE_1, [
            'registrationLink' => 'https://example.com/meetup-1/register',
            'resultsLink' => 'https://example.com/meetup-1/results',
            // An edition without a place of its own is at its series' place - the form's rule for events is no rule here
            'location' => null,
            'dateFrom' => null,
            'dateTo' => null,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('https://example.com/meetup-1/register', $answer['registrationLink']);
        self::assertNull($answer['location']);
        self::assertTrue($answer['isRecurring']);
        self::assertIsArray($answer['series']);
        self::assertSame(CompetitionSeriesFixture::SERIES_OFFLINE, $answer['series']['seriesId']);
    }

    public function testApprovesAPendingCompetitionOnce(): void
    {
        $browser = self::createClient();

        self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_UNAPPROVED . '/approve');
        self::assertResponseStatusCodeSame(204);

        $answer = self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_UNAPPROVED);
        self::assertSame('approved', $answer['status']);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $answer['approvedByPlayerId']);

        self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_UNAPPROVED . '/approve');
        self::assertResponseStatusCodeSame(409);

        // An edition is approved through its series
        self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . CompetitionSeriesFixture::EDITION_UNAPPROVED_1 . '/approve');
        self::assertResponseStatusCodeSame(409);
    }

    public function testSetsTheCompetitionPuzzlesCreatingItsTag(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'PUT', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_UNAPPROVED . '/puzzles', [
            'puzzleIds' => [PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_1000_01],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('Unapproved Puzzle Event', $answer['tagName']);
        self::assertEqualsCanonicalizing(
            [PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_1000_01],
            array_column(self::list($answer['puzzles']), 'puzzleId'),
        );

        $replaced = self::callInternalApi($browser, 'PUT', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_UNAPPROVED . '/puzzles', [
            'puzzleIds' => [PuzzleFixture::PUZZLE_1000_01, PuzzleFixture::PUZZLE_300],
        ]);

        self::assertSame($answer['tagId'], $replaced['tagId']);
        self::assertEqualsCanonicalizing(
            [PuzzleFixture::PUZZLE_1000_01, PuzzleFixture::PUZZLE_300],
            array_column(self::list($replaced['puzzles']), 'puzzleId'),
        );
    }

    public function testCompetitionPuzzlesRefuseUnknownPuzzlesAndSharedTags(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'PUT', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024 . '/puzzles', [
            'puzzleIds' => [PuzzleFixture::PUZZLE_500_01, '018d0003-0000-0000-0000-00000000ffff'],
        ]);
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('018d0003-0000-0000-0000-00000000ffff', self::string($answer['error']));

        self::callInternalApi($browser, 'PUT', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024 . '/puzzles', []);
        self::assertResponseStatusCodeSame(400);

        // Another competition carrying the same tag would change with it
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement('UPDATE competition SET tag_id = :tagId WHERE id = :id', [
            'tagId' => TagFixture::TAG_WJPC,
            'id' => CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024,
        ]);

        self::callInternalApi($browser, 'PUT', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024 . '/puzzles', [
            'puzzleIds' => [PuzzleFixture::PUZZLE_500_01],
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testEveryWriteIsAudited(): void
    {
        $browser = self::createClient();
        $auditLog = self::recordAuditLog();

        $answer = self::callInternalApi($browser, 'POST', '/internal-api/competitions', [
            'name' => 'Audited Event',
            'isOnline' => true,
        ]);
        self::assertResponseStatusCodeSame(201);

        $records = $auditLog->getRecords();
        self::assertCount(1, $records);
        self::assertSame('POST', $records[0]->context['method']);
        self::assertSame('/internal-api/competitions', $records[0]->context['path']);
        self::assertSame(201, $records[0]->context['status']);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $records[0]->context['actingPlayerId']);
        self::assertSame($answer['competitionId'], $records[0]->context['createdId']);
    }

    public function testRouteIdsOfAWriteAreAudited(): void
    {
        $browser = self::createClient();
        $auditLog = self::recordAuditLog();

        self::callInternalApi($browser, 'PATCH', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024, [
            'resultsLink' => 'https://example.com/results',
        ]);
        self::assertResponseIsSuccessful();

        $records = $auditLog->getRecords();
        self::assertCount(1, $records);
        self::assertSame(['competitionId' => CompetitionFixture::COMPETITION_WJPC_2024], $records[0]->context['targetIds']);
    }

    /**
     * Records what the audit channel logs during the next request (the first of a client keeps its container).
     */
    private static function recordAuditLog(): TestHandler
    {
        $auditLog = new TestHandler();
        self::getContainer()->get('monolog.logger.internal_api_audit')->pushHandler($auditLog);

        return $auditLog;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var list<array<string, mixed>> $value */
        return $value;
    }

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
