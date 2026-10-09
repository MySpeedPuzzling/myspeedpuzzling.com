<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Monolog\Handler\TestHandler;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/features/internal-api.md "Organizations, series and drafts": the organization endpoints.
 */
final class OrganizationsInternalApiTest extends WebTestCase
{
    use InternalApiRequests;

    public function testWithoutTokenEveryNewEndpointIsUnauthorizedAsJson(): void
    {
        $browser = self::createClient();
        $organization = '/internal-api/organizations/' . OrganizationFixture::ORGANIZATION_RIVERBEND;
        $series = '/internal-api/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS;
        $competition = '/internal-api/competitions/' . OrganizationFixture::EDITION_LANTERN_1;

        $endpoints = [
            ['GET', '/internal-api/organizations'],
            ['GET', $organization],
            ['POST', '/internal-api/organizations'],
            ['PATCH', $organization],
            ['DELETE', $organization],
            ['POST', $organization . '/approve'],
            ['POST', $organization . '/publish'],
            ['POST', $organization . '/unpublish'],
            ['POST', $organization . '/maintainers'],
            ['DELETE', $organization . '/maintainers/' . PlayerFixture::PLAYER_WITH_FAVORITES],
            ['GET', '/internal-api/series'],
            ['GET', $series],
            ['POST', '/internal-api/series'],
            ['PATCH', $series],
            ['POST', $series . '/publish'],
            ['POST', $series . '/unpublish'],
            ['POST', $series . '/editions'],
            ['PUT', $series . '/organization'],
            ['POST', $series . '/create-organization'],
            ['PUT', $competition . '/organization'],
            ['POST', $competition . '/publish'],
            ['POST', $competition . '/unpublish'],
            ['POST', $competition . '/move'],
            ['POST', '/internal-api/rounds/' . CompetitionRoundFixture::ROUND_WJPC_FINAL . '/move'],
        ];

        foreach ($endpoints as [$method, $uri]) {
            $answer = self::callInternalApi($browser, $method, $uri, ['name' => 'x'], token: null);

            self::assertResponseStatusCodeSame(401, $method . ' ' . $uri);
            self::assertIsString($answer['error'] ?? null, $method . ' ' . $uri);
        }
    }

    public function testListsEveryOrganizationAndFiltersByStatus(): void
    {
        $browser = self::createClient();

        $all = self::callInternalApi($browser, 'GET', '/internal-api/organizations?limit=100');
        self::assertResponseIsSuccessful();
        $ids = array_column(self::list($all['organizations']), 'organizationId');
        foreach ([OrganizationFixture::ORGANIZATION_RIVERBEND, OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT, OrganizationFixture::ORGANIZATION_MAPLE_PENDING, OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT] as $id) {
            self::assertContains($id, $ids);
        }

        $pending = self::callInternalApi($browser, 'GET', '/internal-api/organizations?status=pending&limit=100');
        self::assertEqualsCanonicalizing(
            [OrganizationFixture::ORGANIZATION_MAPLE_PENDING, OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT],
            array_column(self::list($pending['organizations']), 'organizationId'),
        );

        $drafts = self::callInternalApi($browser, 'GET', '/internal-api/organizations?status=draft&limit=100');
        self::assertEqualsCanonicalizing(
            [OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT, OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT],
            array_column(self::list($drafts['organizations']), 'organizationId'),
        );

        $search = self::callInternalApi($browser, 'GET', '/internal-api/organizations?q=RJA');
        self::assertSame([OrganizationFixture::ORGANIZATION_RIVERBEND], array_column(self::list($search['organizations']), 'organizationId'));
        self::assertSame(1, $search['total']);

        self::callInternalApi($browser, 'GET', '/internal-api/organizations?status=nonsense');
        self::assertResponseStatusCodeSame(400);
    }

    public function testDetailHoldsItsFieldsTeamSeriesAndEvents(): void
    {
        $browser = self::createClient();

        $byId = self::callInternalApi($browser, 'GET', '/internal-api/organizations/' . OrganizationFixture::ORGANIZATION_RIVERBEND);
        self::assertResponseIsSuccessful();
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND_NAME, $byId['name']);
        self::assertSame('RJA', $byId['shortName']);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG, $byId['slug']);
        self::assertSame('association', $byId['kind']);
        self::assertSame('us', $byId['countryCode']);
        self::assertSame('Riverbend Valley', $byId['region']);
        self::assertSame(['https://www.instagram.com/riverbendjigsaw', 'https://discord.gg/riverbendjigsaw'], $byId['socialLinks']);
        self::assertSame('approved', $byId['status']);
        self::assertFalse($byId['draft']);
        self::assertTrue($byId['publiclyVisible']);
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $byId['addedByPlayerId']);
        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES], array_column(self::list($byId['maintainers']), 'playerId'));
        self::assertSame(2, $byId['seriesCount']);
        self::assertSame(1, $byId['eventsCount']);
        self::assertEqualsCanonicalizing(
            [OrganizationFixture::SERIES_LANTERN_NIGHTS, OrganizationFixture::SERIES_RIVERBEND_VIRTUAL],
            array_column(self::list($byId['series']), 'seriesId'),
        );
        self::assertSame([OrganizationFixture::COMPETITION_RIVERBEND_OPEN], array_column(self::list($byId['events']), 'competitionId'));

        $bySlug = self::callInternalApi($browser, 'GET', '/internal-api/organizations/' . OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $bySlug['organizationId']);

        $harbor = self::callInternalApi($browser, 'GET', '/internal-api/organizations/' . OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT);
        self::assertSame('approved', $harbor['status']);
        self::assertTrue($harbor['draft']);
        self::assertFalse($harbor['publiclyVisible']);

        self::callInternalApi($browser, 'GET', '/internal-api/organizations/no-such-organization');
        self::assertResponseStatusCodeSame(404);
    }

    public function testCreatesAnApprovedOrganization(): void
    {
        $browser = self::createClient();
        $auditLog = self::recordAuditLog();

        $answer = self::callInternalApi($browser, 'POST', '/internal-api/organizations', [
            'name' => 'Lakeshore Puzzle Federation',
            'shortName' => 'LPF',
            'kind' => 'association',
            'countryCode' => 'CA',
            'region' => 'Lakeshore',
            'about' => 'Puzzle events around the lake.',
            'website' => 'https://lakeshore-puzzles.example',
            'socialLinks' => ['https://www.instagram.com/lakeshorepuzzles', ' https://discord.gg/lakeshore ', ''],
            'maintainerIds' => [PlayerFixture::PLAYER_REGULAR],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('lakeshore-puzzle-federation', $answer['slug']);
        self::assertSame('approved', $answer['status']);
        self::assertFalse($answer['draft']);
        self::assertTrue($answer['publiclyVisible']);
        self::assertSame('ca', $answer['countryCode']);
        self::assertSame(['https://www.instagram.com/lakeshorepuzzles', 'https://discord.gg/lakeshore'], $answer['socialLinks']);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $answer['addedByPlayerId']);
        self::assertSame([PlayerFixture::PLAYER_REGULAR], array_column(self::list($answer['maintainers']), 'playerId'));
        self::assertSame([], $answer['series']);
        // An admin created it - nobody is asked to review it
        self::assertQueuedEmailCount(0);

        $records = $auditLog->getRecords();
        self::assertCount(1, $records);
        self::assertSame($answer['organizationId'], $records[0]->context['createdId']);

        $draft = self::callInternalApi($browser, 'POST', '/internal-api/organizations', [
            'name' => 'Lakeshore Draft Club',
            'slug' => 'lakeshore-draft-club',
            'draft' => true,
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertTrue($draft['draft']);
        self::assertSame('approved', $draft['status']);
        self::assertFalse($draft['publiclyVisible']);
    }

    public function testInvalidFieldsAreListedAtOnce(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'POST', '/internal-api/organizations', [
            'shortName' => str_repeat('x', 31),
            'kind' => 'guild',
            'countryCode' => 'xx',
            'website' => 'not a url',
            'socialLinks' => ['ftp://example.com/files'],
            'slug' => 'Not A Slug',
            'logo' => 'x.png',
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertIsArray($answer['errors']);
        foreach (['name', 'shortName', 'kind', 'countryCode', 'website', 'socialLinks[0]', 'slug', 'logo'] as $field) {
            self::assertArrayHasKey($field, $answer['errors'], $field);
        }

        $tooMany = self::callInternalApi($browser, 'POST', '/internal-api/organizations', [
            'name' => 'Too Many Links Club',
            'socialLinks' => array_map(static fn (int $index): string => 'https://example.com/' . $index, range(1, 11)),
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertIsArray($tooMany['errors']);
        self::assertArrayHasKey('socialLinks', $tooMany['errors']);

        self::callInternalApi($browser, 'POST', '/internal-api/organizations', ['name' => 'X', 'socialLinks' => 'https://example.com']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testATakenSlugIsAConflict(): void
    {
        $browser = self::createClient();

        self::callInternalApi($browser, 'POST', '/internal-api/organizations', [
            'name' => 'Another Riverbend',
            'slug' => OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG,
        ]);
        self::assertResponseStatusCodeSame(409);

        self::callInternalApi($browser, 'PATCH', '/internal-api/organizations/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING, [
            'slug' => OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG,
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testPatchChangesOnlyTheFieldsSentAndARenameKeepsTheSlug(): void
    {
        $browser = self::createClient();
        $uri = '/internal-api/organizations/' . OrganizationFixture::ORGANIZATION_RIVERBEND;

        $answer = self::callInternalApi($browser, 'PATCH', $uri, [
            'name' => 'Riverbend Valley Jigsaw Association',
            'socialLinks' => ['https://www.facebook.com/riverbendjigsaw'],
            'region' => 'Riverbend County',
            'kind' => 'club',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('Riverbend Valley Jigsaw Association', $answer['name']);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG, $answer['slug']);
        self::assertSame(['https://www.facebook.com/riverbendjigsaw'], $answer['socialLinks']);
        self::assertSame('Riverbend County', $answer['region']);
        self::assertSame('club', $answer['kind']);
        // Not sent - kept
        self::assertSame('RJA', $answer['shortName']);
        self::assertSame('https://riverbend-jigsaw.example', $answer['website']);
        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES], array_column(self::list($answer['maintainers']), 'playerId'));

        $answer = self::callInternalApi($browser, 'PATCH', $uri, ['slug' => 'riverbend-valley-jigsaw', 'socialLinks' => null, 'kind' => null, 'maintainerIds' => []]);
        self::assertSame('riverbend-valley-jigsaw', $answer['slug']);
        self::assertSame([], $answer['socialLinks']);
        self::assertNull($answer['kind']);
        self::assertSame([], $answer['maintainers']);

        $cleared = self::callInternalApi($browser, 'PATCH', $uri, ['slug' => null]);
        self::assertResponseStatusCodeSame(400);
        self::assertIsArray($cleared['errors']);
        self::assertArrayHasKey('slug', $cleared['errors']);

        self::callInternalApi($browser, 'PATCH', $uri, ['nmae' => 'typo']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testDraftPublishesAndUnpublishes(): void
    {
        $browser = self::createClient();
        $uri = '/internal-api/organizations/' . OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT;

        self::callInternalApi($browser, 'POST', $uri . '/publish');
        self::assertResponseStatusCodeSame(204);
        self::assertFalse(self::callInternalApi($browser, 'GET', $uri)['draft']);

        self::callInternalApi($browser, 'POST', $uri . '/unpublish');
        self::assertResponseStatusCodeSame(204);
        self::assertTrue(self::callInternalApi($browser, 'GET', $uri)['draft']);

        $published = self::callInternalApi($browser, 'PATCH', $uri, ['draft' => false]);
        self::assertFalse($published['draft']);
        self::assertTrue($published['publiclyVisible']);
    }

    public function testApprovesAPendingOrganizationWithItsPendingSeries(): void
    {
        $browser = self::createClient();
        $uri = '/internal-api/organizations/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING;

        self::callInternalApi($browser, 'POST', $uri . '/approve');
        self::assertResponseStatusCodeSame(204);
        // A player created it - they are told
        self::assertQueuedEmailCount(1);

        $answer = self::callInternalApi($browser, 'GET', $uri);
        self::assertSame('approved', $answer['status']);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $answer['approvedByPlayerId']);
        self::assertSame(['approved'], array_column(self::list($answer['series']), 'status'));

        self::callInternalApi($browser, 'POST', $uri . '/approve');
        self::assertResponseStatusCodeSame(409);
    }

    public function testDeletesOnlyAnEmptyOrganization(): void
    {
        $browser = self::createClient();

        self::callInternalApi($browser, 'DELETE', '/internal-api/organizations/' . OrganizationFixture::ORGANIZATION_RIVERBEND);
        self::assertResponseStatusCodeSame(409);

        self::callInternalApi($browser, 'DELETE', '/internal-api/organizations/' . OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT);
        self::assertResponseStatusCodeSame(204);

        self::callInternalApi($browser, 'GET', '/internal-api/organizations/' . OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAddsAndRemovesTeamMembers(): void
    {
        $browser = self::createClient();
        $uri = '/internal-api/organizations/' . OrganizationFixture::ORGANIZATION_RIVERBEND;

        self::callInternalApi($browser, 'POST', $uri . '/maintainers', ['playerId' => PlayerFixture::PLAYER_REGULAR]);
        self::assertResponseStatusCodeSame(204);
        // Idempotent
        self::callInternalApi($browser, 'POST', $uri . '/maintainers', ['playerId' => PlayerFixture::PLAYER_REGULAR]);
        self::assertResponseStatusCodeSame(204);
        self::assertEqualsCanonicalizing(
            [PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_REGULAR],
            array_column(self::list(self::callInternalApi($browser, 'GET', $uri)['maintainers']), 'playerId'),
        );

        self::callInternalApi($browser, 'DELETE', $uri . '/maintainers/' . PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertResponseStatusCodeSame(204);
        self::assertSame([PlayerFixture::PLAYER_REGULAR], array_column(self::list(self::callInternalApi($browser, 'GET', $uri)['maintainers']), 'playerId'));

        // At most ten besides the creator: a team of ten takes nobody else (409)
        $connection = self::getContainer()->get(Connection::class);
        /** @var list<string> $others */
        $others = $connection->fetchFirstColumn(
            'SELECT id FROM player WHERE id NOT IN (:taken) ORDER BY id LIMIT 10',
            ['taken' => [PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_REGULAR]],
            ['taken' => ArrayParameterType::STRING],
        );

        foreach (array_slice($others, 0, 9) as $playerId) {
            $connection->executeStatement(
                'INSERT INTO organization_maintainer (organization_id, player_id) VALUES (:organizationId, :playerId)',
                ['organizationId' => OrganizationFixture::ORGANIZATION_RIVERBEND, 'playerId' => $playerId],
            );
        }

        self::callInternalApi($browser, 'POST', $uri . '/maintainers', ['playerId' => $others[9]]);
        self::assertResponseStatusCodeSame(409);

        // ... and a PATCH or create listing eleven is a 400
        $eleven = [...$others, PlayerFixture::PLAYER_REGULAR];
        $invalid = self::callInternalApi($browser, 'PATCH', $uri, ['maintainerIds' => $eleven]);
        self::assertResponseStatusCodeSame(400);
        self::assertIsArray($invalid['errors']);
        self::assertArrayHasKey('maintainerIds', $invalid['errors']);

        self::callInternalApi($browser, 'POST', $uri . '/maintainers', ['playerId' => '018d0000-0000-0000-0000-00000000ffff']);
        self::assertResponseStatusCodeSame(404);

        self::callInternalApi($browser, 'POST', $uri . '/maintainers', ['playerId' => 'not-an-id']);
        self::assertResponseStatusCodeSame(400);

        self::callInternalApi($browser, 'POST', $uri . '/maintainers', []);
        self::assertResponseStatusCodeSame(400);
    }

    public function testAssignsOneTimeEventsButNeverAnEdition(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);

        $answer = self::callInternalApi($browser, 'PUT', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_UNAPPROVED . '/organization', [
            'organizationId' => OrganizationFixture::ORGANIZATION_RIVERBEND,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $answer['organizationId']);
        // A pending event moved under an approved organization by an admin is approved at once
        self::assertSame('approved', $answer['status']);

        $out = self::callInternalApi($browser, 'PUT', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_UNAPPROVED . '/organization', [
            'organizationId' => null,
        ]);
        self::assertNull($out['organizationId']);
        self::assertSame('approved', $out['status']);

        self::callInternalApi($browser, 'PUT', '/internal-api/competitions/' . CompetitionSeriesFixture::EDITION_EJJ_69 . '/organization', [
            'organizationId' => OrganizationFixture::ORGANIZATION_RIVERBEND,
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertNull($database->fetchOne('SELECT organization_id FROM competition WHERE id = :id', ['id' => CompetitionSeriesFixture::EDITION_EJJ_69]));

        self::callInternalApi($browser, 'PUT', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_UNAPPROVED . '/organization', []);
        self::assertResponseStatusCodeSame(400);

        self::callInternalApi($browser, 'PUT', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_UNAPPROVED . '/organization', [
            'organizationId' => '018d0042-0000-0000-0000-00000000ffff',
        ]);
        self::assertResponseStatusCodeSame(400);
    }

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
}
