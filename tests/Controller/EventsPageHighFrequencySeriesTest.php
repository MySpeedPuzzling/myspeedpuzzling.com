<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddOrganization;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The events page with a high-frequency series (docs/features/events-page/high-frequency-series.md "Events pages"):
 * the month roll-up's "+N more", the compact index, the search by round puzzle names - never a secret one - and the
 * parents without children of H13 (a series without editions, an organization without series or events).
 */
final class EventsPageHighFrequencySeriesTest extends WebTestCase
{
    public function testAMonthWithManyDatesShowsSixChipsAndMore(): void
    {
        $browser = self::createClient();
        $seed = WeeklySeriesSeed::create(self::getContainer(), past: 0, upcoming: 24, upcomingEveryDays: 1);

        $crawler = $this->page($browser, '/en/events');

        $groups = $crawler->filter('.ev-agenda .ev-row-group')->reduce(static fn (Crawler $row): bool => str_contains($row->text(), 'Lantern Weekly Jam'));
        self::assertGreaterThanOrEqual(1, $groups->count());
        $more = $groups->filter('[data-ev-more-sessions]');
        self::assertGreaterThanOrEqual(1, $more->count(), 'at least one month holds more than six of its 24 daily dates');

        $row = $more->eq(0)->closest('.ev-row');
        self::assertNotNull($row);
        $ids = explode(' ', (string) $row->attr('data-ev-ids'));
        $chips = $row->filter('a.ev-session:not([data-ev-more-sessions])');
        self::assertCount(6, $chips);
        self::assertSame('+' . (count($ids) - 6) . ' more →', trim($more->eq(0)->text()));
        self::assertSame('/en/series/' . $seed['slug'], $more->eq(0)->attr('href'));
        self::assertSame(array_slice($ids, 6), explode(' ', (string) $more->eq(0)->attr('data-ev-ids')), 'the calendar lights it up for its dates');
        self::assertStringContainsString(count($ids) . ' sessions', $row->filter('.ev-sessions-n')->text());
    }

    /**
     * The page ships the compact index (P25): an edition entry without its series' name, place and scope, its link as
     * the path after its series'
     */
    public function testThePageShipsTheCompactIndex(): void
    {
        $browser = self::createClient();
        $seed = WeeklySeriesSeed::create(self::getContainer(), past: 20, upcoming: 2);

        $crawler = $this->page($browser, '/en/events');
        /** @var list<array<string, mixed>> $index */
        $index = json_decode($crawler->filter('script[data-events-index]')->text(), true, flags: JSON_THROW_ON_ERROR);

        $series = array_values(array_filter($index, static fn (array $entry): bool => ($entry['k'] ?? null) === 's' && ($entry['n'] ?? null) === 'Lantern Weekly Jam'));
        self::assertCount(1, $series);
        self::assertSame('/en/series/' . $seed['slug'], $series[0]['u']);

        $editions = array_values(array_filter($index, static fn (array $entry): bool => ($entry['sid'] ?? null) === $series[0]['id']));
        self::assertCount(22, $editions);

        foreach ($editions as $edition) {
            self::assertSame('d', $edition['k']);
            self::assertArrayNotHasKey('n', $edition, 'the series name comes from the series entry');
            self::assertArrayNotHasKey('p', $edition);
            self::assertArrayNotHasKey('sc', $edition);
            self::assertArrayNotHasKey('u', $edition);
            self::assertArrayNotHasKey('cm', $edition, 'one session each');
            self::assertIsString($edition['es']);
            self::assertMatchesRegularExpression('/^jam-no-\d+$/', $edition['es']);
            self::assertIsString($edition['x']);
            self::assertStringContainsString('copper lighthouse', $edition['x']);
        }
    }

    /**
     * A past edition is found by its round puzzle's name (with and without JavaScript - the server renders `?q=`
     * filtered); a puzzle its round still keeps secret is neither listed nor findable, nor anywhere in the page
     */
    public function testAPastEditionIsFoundByItsPuzzleButNeverBySecretOne(): void
    {
        $browser = self::createClient();
        $seed = WeeklySeriesSeed::create(self::getContainer(), past: 3, upcoming: 0);
        $scenario = new SeriesEditionScenario(self::getContainer());
        $day = new DateTimeImmutable('-4 days')->format('Y-m-d');
        $secretEdition = $scenario->edition($seed['seriesId'], 'Secret Jam', $day);
        $scenario->round($secretEdition, RoundCategory::Solo, $day . ' 19:00', puzzleIds: [$scenario->puzzle('Velvet Comet')], secret: true);

        $crawler = $this->page($browser, '/en/events?q=copper+lighthouse');
        $titles = $crawler->filter('[data-ev-search-lines] .ev-line-title')->each(static fn (Crawler $title): string => trim($title->text()));
        self::assertContains('Lantern Weekly Jam · Jam No. 1', $titles);
        self::assertContains('Lantern Weekly Jam · Jam No. 3', $titles);
        self::assertNotContains('Lantern Weekly Jam · Secret Jam', $titles);

        $secret = $this->page($browser, '/en/events?q=velvet+comet');
        self::assertCount(0, $secret->filter('[data-ev-search-lines] .ev-line'));

        $content = (string) $this->page($browser, '/en/events')->html();
        self::assertStringNotContainsString('Velvet Comet', $content);
        self::assertStringNotContainsString('velvet comet', $content);
        self::assertStringContainsString('"en":"Secret Jam"', $content, 'the edition itself is in the index');
    }

    /**
     * H13: a series without editions is in the directory with "No dates yet"
     */
    public function testASeriesWithoutEditionsIsInTheDirectory(): void
    {
        $browser = self::createClient();
        new SeriesEditionScenario(self::getContainer())->series('Moonlit Sprint Cup');

        $crawler = $this->page($browser, '/en/events');
        $line = $crawler->filter('[data-ev-series-group="online"] .ev-series-line')->reduce(static fn (Crawler $line): bool => str_contains($line->text(), 'Moonlit Sprint Cup'));

        self::assertCount(1, $line);
        self::assertSame('No dates yet', $line->filter('.ev-series-next')->text());
    }

    /**
     * H13: an organization without series or events keeps its clean empty page
     */
    public function testAnOrganizationWithoutSeriesOrEventsSaysNothingIsPlanned(): void
    {
        $browser = self::createClient();
        $organizationId = Uuid::uuid7();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddOrganization(
            organizationId: $organizationId,
            playerId: PlayerFixture::PLAYER_ADMIN,
            name: 'Starling Puzzle Collective',
            shortName: null,
            about: null,
            website: null,
            socialLinks: [],
            countryCode: null,
            region: null,
            kind: null,
            logo: null,
            maintainerIds: [],
            slug: 'starling-puzzle-collective',
            approve: true,
        ));

        $crawler = $this->page($browser, '/en/organizations/starling-puzzle-collective');

        self::assertStringContainsString('Nothing planned yet.', $crawler->filter('[data-org-nothing-planned]')->text());
        self::assertCount(0, $crawler->filter('[data-org-nothing-planned] a'), 'a guest gets no "Add event"');
        self::assertCount(0, $crawler->filter('[data-org-run]'));
        self::assertCount(0, $crawler->filter('[data-org-past]'));
    }

    private function page(KernelBrowser $browser, string $url): Crawler
    {
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        return $crawler;
    }
}
