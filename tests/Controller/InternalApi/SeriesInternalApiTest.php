<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/internal-api.md "Organizations, series and drafts": the series and edition endpoints.
 */
final class SeriesInternalApiTest extends WebTestCase
{
    use InternalApiRequests;

    public function testListsSeriesAndFiltersByStatus(): void
    {
        $browser = self::createClient();

        $all = self::callInternalApi($browser, 'GET', '/internal-api/series?limit=100');
        self::assertResponseIsSuccessful();
        $ids = array_column(self::list($all['series']), 'seriesId');
        self::assertContains(CompetitionSeriesFixture::SERIES_EJJ, $ids);
        self::assertContains(OrganizationFixture::SERIES_QUIET_PINES_DRAFT, $ids);

        $drafts = self::callInternalApi($browser, 'GET', '/internal-api/series?status=draft&limit=100');
        self::assertSame([OrganizationFixture::SERIES_QUIET_PINES_DRAFT], array_column(self::list($drafts['series']), 'seriesId'));

        $pending = self::callInternalApi($browser, 'GET', '/internal-api/series?status=pending&limit=100');
        $pendingIds = array_column(self::list($pending['series']), 'seriesId');
        self::assertContains(CompetitionSeriesFixture::SERIES_UNAPPROVED, $pendingIds);
        self::assertContains(OrganizationFixture::SERIES_MAPLE_PENDING, $pendingIds);
        self::assertNotContains(CompetitionSeriesFixture::SERIES_EJJ, $pendingIds);

        $search = self::callInternalApi($browser, 'GET', '/internal-api/series?q=lantern');
        self::assertSame([OrganizationFixture::SERIES_LANTERN_NIGHTS], array_column(self::list($search['series']), 'seriesId'));

        self::callInternalApi($browser, 'GET', '/internal-api/series?status=nonsense');
        self::assertResponseStatusCodeSame(400);
    }

    public function testDetailHoldsItsOrganizationAndEveryEdition(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'GET', '/internal-api/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG);
        self::assertResponseIsSuccessful();
        self::assertSame(OrganizationFixture::SERIES_LANTERN_NIGHTS, $answer['seriesId']);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $answer['organizationId']);
        self::assertIsArray($answer['organization']);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG, $answer['organization']['slug']);
        self::assertSame('21+', $answer['eligibility']);
        self::assertSame('First Monday of the month, 7 pm', $answer['schedule']);
        self::assertSame('approved', $answer['status']);
        self::assertFalse($answer['draft']);
        self::assertSame(3, $answer['editionsCount']);

        $editions = self::list($answer['editions']);
        self::assertEqualsCanonicalizing(
            [OrganizationFixture::EDITION_LANTERN_1, OrganizationFixture::EDITION_LANTERN_2, OrganizationFixture::EDITION_LANTERN_DRAFT],
            array_column($editions, 'competitionId'),
        );
        $draftEdition = array_values(array_filter($editions, static fn (array $edition): bool => $edition['competitionId'] === OrganizationFixture::EDITION_LANTERN_DRAFT))[0];
        self::assertTrue($draftEdition['draft']);
        self::assertSame('approved', $draftEdition['status']);
        self::assertFalse($draftEdition['publiclyVisible']);
        self::assertSame(OrganizationFixture::EDITION_LANTERN_DRAFT_SLUG, $draftEdition['slug']);

        $byId = self::callInternalApi($browser, 'GET', '/internal-api/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS);
        self::assertSame(OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG, $byId['slug']);

        self::callInternalApi($browser, 'GET', '/internal-api/series/no-such-series');
        self::assertResponseStatusCodeSame(404);
    }

    public function testCreatesASeriesApprovedUnderAnApprovedOrganizationOrWhenAsked(): void
    {
        $browser = self::createClient();

        $underOrganization = self::callInternalApi($browser, 'POST', '/internal-api/series', [
            'name' => 'Riverbend Library Puzzle Hour',
            'organizationId' => OrganizationFixture::ORGANIZATION_RIVERBEND,
            'isOnline' => false,
            'location' => 'Riverbend Public Library',
            'locationCountryCode' => 'us',
            'eligibility' => 'All ages',
            'schedule' => 'Second Saturday of the month, 10 am',
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('riverbend-library-puzzle-hour', $underOrganization['slug']);
        self::assertSame('approved', $underOrganization['status']);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $underOrganization['organizationId']);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $underOrganization['addedByPlayerId']);
        // Nobody is e-mailed: an admin created it
        self::assertQueuedEmailCount(0);

        $pending = self::callInternalApi($browser, 'POST', '/internal-api/series', ['name' => 'Online Puzzle Sprint', 'isOnline' => true, 'slug' => 'online-puzzle-sprint']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('pending', $pending['status']);
        self::assertNull($pending['organizationId']);

        $approved = self::callInternalApi($browser, 'POST', '/internal-api/series', ['name' => 'Online Puzzle Marathon', 'isOnline' => true, 'approve' => true, 'draft' => true]);
        self::assertSame('approved', $approved['status']);
        self::assertTrue($approved['draft']);
        self::assertFalse($approved['publiclyVisible']);
    }

    public function testInvalidSeriesAreRefused(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'POST', '/internal-api/series', [
            'isOnline' => false,
            'link' => 'not a url',
            'eligibility' => str_repeat('x', 121),
            'schedule' => str_repeat('x', 161),
            'organizationId' => '018d0042-0000-0000-0000-00000000ffff',
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertIsArray($answer['errors']);
        foreach (['name', 'location', 'link', 'eligibility', 'schedule', 'organizationId'] as $field) {
            self::assertArrayHasKey($field, $answer['errors'], $field);
        }

        self::callInternalApi($browser, 'POST', '/internal-api/series', ['name' => 'Copy', 'isOnline' => true, 'slug' => OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testPatchChangesTheFieldsSentAndMovesTheSeries(): void
    {
        $browser = self::createClient();
        $uri = '/internal-api/series/' . CompetitionSeriesFixture::SERIES_EJJ;

        $answer = self::callInternalApi($browser, 'PATCH', $uri, [
            'name' => 'Euro Jigsaw Jam Online',
            'eligibility' => 'Everyone',
            'schedule' => 'Every month',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('Euro Jigsaw Jam Online', $answer['name']);
        self::assertSame('euro-jigsaw-jam-series', $answer['slug']);
        self::assertSame('Everyone', $answer['eligibility']);
        self::assertSame('https://eurojj.com', $answer['link']);
        self::assertSame([PlayerFixture::PLAYER_ADMIN], array_column(self::list($answer['maintainers']), 'playerId'));

        $moved = self::callInternalApi($browser, 'PATCH', $uri, ['organizationId' => OrganizationFixture::ORGANIZATION_RIVERBEND, 'slug' => 'euro-jigsaw-jam-online']);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $moved['organizationId']);
        self::assertSame('euro-jigsaw-jam-online', $moved['slug']);

        $out = self::callInternalApi($browser, 'PUT', $uri . '/organization', ['organizationId' => null]);
        self::assertResponseIsSuccessful();
        self::assertNull($out['organizationId']);

        $in = self::callInternalApi($browser, 'PUT', $uri . '/organization', ['organizationId' => OrganizationFixture::ORGANIZATION_RIVERBEND]);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $in['organizationId']);

        self::callInternalApi($browser, 'PATCH', $uri, ['slug' => '']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testDraftOfASeries(): void
    {
        $browser = self::createClient();

        self::callInternalApi($browser, 'POST', '/internal-api/series/' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT . '/publish');
        self::assertResponseStatusCodeSame(204);
        $published = self::callInternalApi($browser, 'GET', '/internal-api/series/' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT);
        self::assertFalse($published['draft']);
        self::assertTrue($published['publiclyVisible']);

        $unpublished = self::callInternalApi($browser, 'PATCH', '/internal-api/series/' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT, ['draft' => true]);
        self::assertTrue($unpublished['draft']);

        // An edition somebody joined keeps its series published
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new JoinCompetition(OrganizationFixture::EDITION_LANTERN_1, PlayerFixture::PLAYER_REGULAR));

        $refused = self::callInternalApi($browser, 'POST', '/internal-api/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS . '/unpublish');
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('participants', self::string($refused['error']));

        self::callInternalApi($browser, 'PATCH', '/internal-api/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS, ['draft' => true]);
        self::assertResponseStatusCodeSame(409);
        self::assertFalse(self::callInternalApi($browser, 'GET', '/internal-api/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS)['draft']);
    }

    public function testCreatesAnEdition(): void
    {
        $browser = self::createClient();
        $uri = '/internal-api/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS . '/editions';

        $answer = self::callInternalApi($browser, 'POST', $uri, [
            'name' => 'Lantern Night Three',
            'dateFrom' => '2026-12-07',
            'dateTo' => '2026-12-07',
            'registrationLink' => 'https://riverbend-jigsaw.example/register',
            'resultsLink' => 'https://riverbend-jigsaw.example/results',
            'link' => 'https://riverbend-jigsaw.example/lantern',
            'description' => 'The December night.',
            'eligibility' => '21+ with ID',
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('lantern-night-three', $answer['slug']);
        self::assertSame('2026-12-07', $answer['dateFrom']);
        self::assertSame('Riverbend', $answer['location']);
        self::assertFalse($answer['isOnline']);
        self::assertSame('21+ with ID', $answer['eligibility']);
        self::assertSame('approved', $answer['status']);
        self::assertTrue($answer['publiclyVisible']);
        self::assertFalse($answer['draft']);
        self::assertIsArray($answer['series']);
        self::assertSame(OrganizationFixture::SERIES_LANTERN_NIGHTS, $answer['series']['seriesId']);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $answer['series']['organizationId']);
        self::assertSame(0, $answer['roundsCount']);

        $draft = self::callInternalApi($browser, 'POST', $uri, ['name' => 'Lantern Night Four', 'slug' => 'lantern-night-4', 'dateFrom' => '2027-01-04', 'dateTo' => '2027-01-04', 'draft' => true]);
        self::assertSame('lantern-night-4', $draft['slug']);
        self::assertTrue($draft['draft']);
        self::assertFalse($draft['publiclyVisible']);

        self::callInternalApi($browser, 'POST', $uri, ['name' => 'Copy', 'slug' => 'lantern-night-4', 'dateFrom' => '2027-01-04', 'dateTo' => '2027-01-04']);
        self::assertResponseStatusCodeSame(409);

        $invalid = self::callInternalApi($browser, 'POST', $uri, ['dateFrom' => '2027-01-05', 'dateTo' => '2027-01-04', 'link' => 'nope', 'slug' => 'Bad Slug']);
        self::assertResponseStatusCodeSame(400);
        self::assertIsArray($invalid['errors']);
        foreach (['name', 'dateTo', 'link', 'slug'] as $field) {
            self::assertArrayHasKey($field, $invalid['errors'], $field);
        }

        self::callInternalApi($browser, 'POST', '/internal-api/series/018d0042-0000-0000-0000-00000000ffff/editions', ['name' => 'X', 'dateFrom' => '2027-01-04', 'dateTo' => '2027-01-04']);
        self::assertResponseStatusCodeSame(404);
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
