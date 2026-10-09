<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OAuth2TestHelper;
use SpeedPuzzling\Web\Tests\OpenApiAssertions;
use SpeedPuzzling\Web\Tests\PatTestHelper;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/series (docs/features/events-page/high-frequency-series.md "API v1", P16): the publicly visible series a
 * client can link a solving time to with series_id - drafts, series waiting for approval and rejected ones never.
 *
 * @phpstan-type SeriesItem array{id: string, name: string, shortcut: null|string, slug: null|string, logo: null|string, is_online: bool, location: null|string, country_code: null|string, link: null|string, organization_name: null|string, editions_count: int, next_date: null|string, last_date: null|string}
 */
final class SeriesListEndpointTest extends WebTestCase
{
    use OpenApiAssertions;
    use QueryCountAssertions;

    private const string ENDPOINT = '/api/v1/series';

    public function testListsPubliclyVisibleSeriesOnly(): void
    {
        $browser = self::createClient();
        $scenario = new SeriesEditionScenario(self::getContainer());
        $public = $scenario->series('Lantern Weekly Jam');
        $draft = $scenario->series('Moonlight Sprint League', draft: true);
        $pending = $scenario->series('Harbor Puzzle Club Nights', public: false);
        $this->authenticatePat($browser);

        $series = $this->fetch($browser);
        $ids = array_column($series, 'id');

        self::assertContains($public, $ids);
        self::assertContains(OrganizationFixture::SERIES_LANTERN_NIGHTS, $ids);
        self::assertNotContains($draft, $ids);
        self::assertNotContains($pending, $ids);
        self::assertNotContains(OrganizationFixture::SERIES_QUIET_PINES_DRAFT, $ids);
        self::assertNotContains(OrganizationFixture::SERIES_MAPLE_PENDING, $ids);
        self::assertNotContains(CompetitionSeriesFixture::SERIES_UNAPPROVED, $ids);

        // By name, letter case ignored
        $names = array_map(mb_strtolower(...), array_column($series, 'name'));
        $sorted = $names;
        sort($sorted);
        self::assertSame($sorted, $names);
    }

    /**
     * Every field; the edition facts count publicly visible editions only (a draft edition neither counts nor dates
     * the series) - an edition held today is neither next nor last
     */
    public function testCarriesTheSeriesFieldsAndItsEditionDays(): void
    {
        $browser = self::createClient();
        $scenario = new SeriesEditionScenario(self::getContainer());
        $today = self::getContainer()->get(ClockInterface::class)->now();
        $jam = $scenario->series('Lantern Weekly Jam');
        $scenario->edition($jam, 'Jam No. 151', $today->modify('-40 days')->format('Y-m-d'));
        $scenario->edition($jam, 'Jam No. 152', $today->modify('-12 days')->format('Y-m-d'));
        $scenario->edition($jam, 'Jam No. 153', $today->format('Y-m-d'));
        $scenario->edition($jam, 'Jam No. 154', $today->modify('+5 days')->format('Y-m-d'));
        $scenario->edition($jam, 'Jam No. 155', $today->modify('+9 days')->format('Y-m-d'));
        $scenario->edition($jam, 'Jam No. 156 - date to come', null);
        $scenario->edition($jam, 'Jam No. 157', $today->modify('+2 days')->format('Y-m-d'), draft: true);
        $roundsOnly = $scenario->series('Moonlight Sprint League', online: false);
        $scenario->round($scenario->edition($roundsOnly, 'Sprint No. 1', null), RoundCategory::Solo, $today->modify('-3 days')->format('Y-m-d') . ' 19:00');
        $empty = $scenario->series('Copper Kettle Puzzle Night');
        $this->authenticatePat($browser);

        $listed = $this->byId($this->fetch($browser));

        self::assertSame([
            'id' => $jam,
            'name' => 'Lantern Weekly Jam',
            'shortcut' => null,
            'slug' => 'hfs-' . substr(str_replace('-', '', $jam), -12),
            'logo' => null,
            'is_online' => true,
            'location' => null,
            'country_code' => null,
            'link' => null,
            'organization_name' => null,
            'editions_count' => 6,
            'next_date' => $today->modify('+5 days')->format('Y-m-d'),
            'last_date' => $today->modify('-12 days')->format('Y-m-d'),
        ], $listed[$jam]);

        // An undated edition is dated by its first round; an in-person series carries its place
        self::assertSame(1, $listed[$roundsOnly]['editions_count']);
        self::assertSame($today->modify('-3 days')->format('Y-m-d'), $listed[$roundsOnly]['last_date']);
        self::assertNull($listed[$roundsOnly]['next_date']);
        self::assertFalse($listed[$roundsOnly]['is_online']);
        self::assertSame('Harbor Town', $listed[$roundsOnly]['location']);
        self::assertSame('cz', $listed[$roundsOnly]['country_code']);

        // A series without editions is listed too (H13) - a valid series_id
        self::assertSame(0, $listed[$empty]['editions_count']);
        self::assertNull($listed[$empty]['next_date']);
        self::assertNull($listed[$empty]['last_date']);
    }

    /**
     * The organization is named only while it is publicly visible; the link carries the UTM source like the
     * competition list's
     */
    public function testNamesOnlyAPubliclyVisibleOrganization(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser);

        $series = $this->byId($this->fetch($browser));

        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND_NAME, $series[OrganizationFixture::SERIES_LANTERN_NIGHTS]['organization_name']);
        self::assertSame(OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG, $series[OrganizationFixture::SERIES_LANTERN_NIGHTS]['slug']);
        // Its draft edition does not count
        self::assertSame(2, $series[OrganizationFixture::SERIES_LANTERN_NIGHTS]['editions_count']);
        // A published series of a draft organization: listed, its organization not named
        self::assertNull($series[OrganizationFixture::SERIES_HARBOR_CLUB_MEETS]['organization_name']);

        foreach ($series as $item) {
            if ($item['link'] !== null) {
                self::assertStringContainsString('utm_source=myspeedpuzzling', $item['link']);
            }
        }
    }

    public function testAnyTokenMayListAndNoTokenMayNot(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::ENDPOINT);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        // A client_credentials token - no player behind it, no scope needed: events are public
        OAuth2TestHelper::addBearerToken($browser, OAuth2TestHelper::createAccessToken($browser, OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID, OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID));
        $browser->request('GET', self::ENDPOINT);
        self::assertResponseIsSuccessful();
    }

    /**
     * One statement whatever the number of series, after the token's own (PAT lookup)
     */
    public function testQueryBudget(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser);

        $this->startCountingQueries($browser);
        $count = count($this->fetch($browser));
        $statements = $this->queryCount($browser);

        $scenario = new SeriesEditionScenario(self::getContainer());

        foreach (['Lantern Weekly Jam', 'Moonlight Sprint League', 'Copper Kettle Puzzle Night'] as $name) {
            $scenario->edition($scenario->series($name), 'No. 1', '2026-03-02');
        }

        $this->startCountingQueries($browser);
        self::assertCount($count + 3, $this->fetch($browser));
        self::assertSame($statements, $this->queryCount($browser), 'More series cost no statement more.');
        self::assertSame(2, $statements, 'The PAT lookup and the list.');
    }

    public function testOpenApiDocumentsTheEndpoint(): void
    {
        $browser = self::createClient();

        $document = $this->openApiDocument($browser);

        /** @var array<string, array{get: array{tags: list<string>, summary: string, description: string}}> $paths */
        $paths = $document['paths'];
        self::assertArrayHasKey('/api/v1/series', $paths);
        self::assertSame(['Competitions'], $paths['/api/v1/series']['get']['tags']);
        self::assertStringContainsString('series_id', $paths['/api/v1/series']['get']['description']);

        /** @var array{schemas: array<string, array{properties: array<string, mixed>}>} $components */
        $components = $document['components'];
        $schemas = $components['schemas'];
        self::assertSame(['series', 'count'], array_keys($schemas['SeriesList']['properties']));
        self::assertSame(
            ['id', 'name', 'shortcut', 'slug', 'logo', 'is_online', 'location', 'country_code', 'link', 'organization_name', 'editions_count', 'next_date', 'last_date'],
            array_keys($schemas['SeriesListItemResponse']['properties']),
        );
    }

    private function authenticatePat(KernelBrowser $browser): void
    {
        PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, PlayerFixture::PLAYER_REGULAR));
    }

    /**
     * @return list<SeriesItem>
     */
    private function fetch(KernelBrowser $browser): array
    {
        $browser->request('GET', self::ENDPOINT);
        self::assertResponseIsSuccessful();

        /** @var array{count: int, series: list<SeriesItem>} $response */
        $response = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount($response['count'], $response['series']);

        return $response['series'];
    }

    /**
     * @param list<SeriesItem> $series
     *
     * @return array<string, SeriesItem>
     */
    private function byId(array $series): array
    {
        return array_column($series, null, 'id');
    }
}
