<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddEdition;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

final class CompetitionSeriesDetailControllerTest extends WebTestCase
{
    public function testApprovedSeriesIsIndexable(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/series/euro-jigsaw-jam-series');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('meta[name="robots"][content="index, follow"]');
        self::assertCount(1, $crawler->filter('meta[name="robots"]'));
    }

    public function testUnapprovedSeriesIsNotIndexable(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/series/pending-puzzle-league');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
        self::assertCount(1, $crawler->filter('meta[name="robots"]'));
    }

    public function testRejectedSeriesIsNotIndexable(): void
    {
        $browser = self::createClient();
        // approve() and reject() do not clear each other - a rejection vetoes a stale approval
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_series SET rejected_at = NOW() WHERE id = :id',
            ['id' => CompetitionSeriesFixture::SERIES_EJJ],
        );

        $browser->request('GET', '/en/series/euro-jigsaw-jam-series');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
    }

    public function testUndatedEditionWithoutRoundsIsListedWithDateNotSet(): void
    {
        $browser = self::createClient();
        $undatedId = self::addUndatedEdition();

        $crawler = $browser->request('GET', '/en/series/euro-jigsaw-jam-series');

        $this->assertResponseIsSuccessful();
        $card = $crawler->filter(sprintf('[data-series-edition="%s"]', $undatedId));
        self::assertCount(1, $card, 'An edition without a date and without rounds must never vanish from the series page');
        self::assertStringContainsString('Pinecone Speed Cup No. 17', $card->text());
        self::assertSame('Date not set', trim($card->filter('[data-edition-date-not-set]')->text()));

        // With the upcoming editions - the Next card first, the undated one last
        $upcomingCards = $crawler->filter('[data-series-edition]');
        self::assertSame($undatedId, $upcomingCards->last()->attr('data-series-edition'));
        self::assertSame(CompetitionSeriesFixture::EDITION_EJJ_69, $upcomingCards->first()->attr('data-series-edition'));
    }

    public function testJsonLdLeavesOutEditionsWithoutAStartDate(): void
    {
        $browser = self::createClient();
        self::addUndatedEdition();

        $browser->request('GET', '/en/series/euro-jigsaw-jam-series');

        $this->assertResponseIsSuccessful();
        $series = self::eventSeriesJsonLd((string) $browser->getResponse()->getContent());
        self::assertIsArray($series['subEvent'] ?? null);
        $names = [];
        foreach ($series['subEvent'] as $subEvent) {
            self::assertIsArray($subEvent);
            self::assertIsString($subEvent['startDate'] ?? null, 'Every sub-event has a startDate');
            $names[] = $subEvent['name'] ?? null;
        }
        // One sub-event per session, named like the event (series · edition)
        self::assertContains('Euro Jigsaw Jam · EJJ #69 — May 2026', $names);
        self::assertNotContains('Pinecone Speed Cup No. 17', $names);
    }

    public function testEditionCardShowsTheEditionsOwnLogo(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition SET logo = 'ejj-69-logo.png' WHERE id = :id",
            ['id' => CompetitionSeriesFixture::EDITION_EJJ_69],
        );

        $crawler = $browser->request('GET', '/en/series/euro-jigsaw-jam-series');

        $this->assertResponseIsSuccessful();
        $logo = $crawler->filter(sprintf('[data-series-edition="%s"] img', CompetitionSeriesFixture::EDITION_EJJ_69));
        self::assertCount(1, $logo);
        self::assertStringEndsWith('/preset:puzzle_small/plain/ejj-69-logo.png', (string) $logo->attr('src'));
        // An edition without its own logo gets none on its card - the series logo is in the page header
        self::assertCount(0, $crawler->filter(sprintf('[data-series-edition="%s"] img', CompetitionSeriesFixture::EDITION_EJJ_68)));
    }

    public function testUndatedEditionIsListedOnTheManagementPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $undatedId = self::addUndatedEdition();

        $crawler = $browser->request('GET', '/en/manage-series/' . CompetitionSeriesFixture::SERIES_EJJ);

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('Pinecone Speed Cup No. 17', $crawler->filter('main')->text());
        self::assertSame('Date not set', trim($crawler->filter('[data-edition-date-not-set]')->text()));
        // ... with its edit and delete buttons, so the organiser can fix or remove it
        self::assertCount(1, $crawler->filter(sprintf('a[href^="/en/edit-event/%s"]', $undatedId)));
        self::assertCount(1, $crawler->filter(sprintf('#deleteEditionModal-%s', $undatedId)));
    }

    /**
     * A duplicate like the one on production: no date, no rounds.
     */
    private static function addUndatedEdition(): string
    {
        $editionId = Uuid::uuid7();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddEdition(
            competitionId: $editionId,
            seriesId: CompetitionSeriesFixture::SERIES_EJJ,
            name: 'Pinecone Speed Cup No. 17',
            dateFrom: null,
            dateTo: null,
            registrationLink: null,
            resultsLink: null,
        ));

        return $editionId->toString();
    }

    /**
     * @return array<mixed>
     */
    private static function eventSeriesJsonLd(string $content): array
    {
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $content, $matches);

        foreach ($matches[1] as $json) {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

            if (is_array($decoded) && ($decoded['@type'] ?? null) === 'EventSeries') {
                return $decoded;
            }
        }

        self::fail('The series page emits EventSeries JSON-LD');
    }

    public function testJsonLdImageIsTheStrippedMediumLogo(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition_series SET logo = 'ejj-logo.png' WHERE id = :id",
            ['id' => CompetitionSeriesFixture::SERIES_EJJ],
        );

        $browser->request('GET', '/en/series/euro-jigsaw-jam-series');

        $this->assertResponseIsSuccessful();
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', (string) $browser->getResponse()->getContent(), $matches);

        $images = [];
        foreach ($matches[1] as $json) {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

            if (is_array($decoded) && ($decoded['@type'] ?? null) === 'EventSeries') {
                $images[] = $decoded['image'] ?? null;
            }
        }

        self::assertCount(1, $images, 'The series page emits one EventSeries JSON-LD');
        self::assertIsString($images[0]);
        // The large stripped preset, never the uploaded original (may carry EXIF/GPS)
        self::assertStringEndsWith('/preset:puzzle_large/plain/ejj-logo.png', $images[0]);
    }

    /**
     * A series with 200+ editions (docs/features/events-page/high-frequency-series.md "Series page for 200+
     * editions"): every line is in the HTML (without JavaScript everything shows), the filter bar waits hidden for its
     * controller, the past years are month sections with the newest month of each year open, and every row and line
     * carries what the filter reads - never the name of a puzzle its round still keeps secret
     */
    public function testASeriesWithTwoHundredEditionsGetsTheFilterBarAndMonthSections(): void
    {
        $browser = self::createClient();
        $seed = WeeklySeriesSeed::create(self::getContainer());
        $scenario = new SeriesEditionScenario(self::getContainer());
        $secretEdition = $scenario->edition($seed['seriesId'], 'Secret Jam', new DateTimeImmutable('-2 days')->format('Y-m-d'));
        $scenario->round($secretEdition, RoundCategory::Team, new DateTimeImmutable('-2 days')->format('Y-m-d') . ' 19:00', puzzleIds: [$scenario->puzzle('Velvet Comet')], secret: true);

        $crawler = $browser->request('GET', '/en/series/' . $seed['slug']);

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertSame('series-filter', $crawler->filter('.ev-detail-main')->attr('data-controller'));

        $bar = $crawler->filter('[data-series-filter-target="bar"]');
        self::assertCount(1, $bar);
        self::assertNotNull($bar->attr('hidden'), 'without JavaScript there is no filter bar');
        self::assertSame(['All', 'Solo', 'Pairs', 'Teams'], $bar->filter('[data-series-filter-target="chip"]')->each(static fn (Crawler $chip): string => trim($chip->text())));
        self::assertSame('Search dates or puzzles…', $bar->filter('input[type="search"]')->attr('placeholder'));
        $months = $bar->filter('select[data-series-filter-target="jump"] option[value!=""]');
        self::assertGreaterThanOrEqual(20, $months->count(), 'a month to jump to for every month with a date');

        // Every past line is in the HTML, in month sections - the newest month of each year open
        self::assertCount(201, $crawler->filter('[data-series-past] .ev-line'));
        $years = $crawler->filter('[data-series-past] .ev-series-year');
        self::assertGreaterThanOrEqual(2, $years->count());
        $years->each(static function (Crawler $year): void {
            $sections = $year->filter('details[data-series-past-month]');
            self::assertGreaterThanOrEqual(1, $sections->count());
            self::assertNotNull($sections->eq(0)->attr('open'), 'the newest month is open');
            self::assertCount(1, $year->filter('details[data-series-past-month][open]'), 'only the newest month');
        });
        self::assertCount(0, $crawler->filter('[data-series-past] .ev-series-show-all'), 'months replace the five-line preview');
        self::assertSame('0', $crawler->filter('[data-series-past]')->attr('data-series-archive-preview-value'));

        // What the filter reads
        $tenth = $crawler->filter('[data-series-past] .ev-line')->reduce(static fn (Crawler $line): bool => str_contains($line->text(), 'Jam No. 190'));
        self::assertCount(1, $tenth);
        self::assertSame('duo', $tenth->attr('data-categories'));
        self::assertStringContainsString('jam no. 190 copper lighthouse', (string) $tenth->attr('data-search'));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}$/', (string) $tenth->attr('data-month'));
        // ... and what the line shows: its round's category (the series has three) and its puzzle
        self::assertSame('Pair', trim($tenth->filter('[data-series-rounds] [data-round-category="duo"]')->text()));
        self::assertSame('Copper Lighthouse', trim($tenth->filter('[data-series-puzzles]')->text()));
        // The month select jumps to the upcoming months and the past ones apart - a month may be in both
        $jumpTargets = $crawler->filter('[data-series-jump]')->each(static fn (Crawler $target): string => (string) $target->attr('data-series-jump'));
        foreach ($months->each(static fn (Crawler $option): string => (string) $option->attr('value')) as $value) {
            self::assertContains($value, $jumpTargets, 'a target for ' . $value);
        }
        self::assertSame('solo', $crawler->filter('[data-series-upcoming] .ev-row')->eq(0)->attr('data-categories'));

        // The round keeps its puzzle secret: its name is nowhere, the round's category is
        self::assertStringNotContainsString('Velvet Comet', $content);
        self::assertStringNotContainsString('velvet comet', $content);
        $secretLine = $crawler->filter('[data-series-past] .ev-line')->reduce(static fn (Crawler $line): bool => str_contains($line->text(), 'Secret Jam'));
        self::assertSame('team', $secretLine->attr('data-categories'));
        self::assertStringStartsWith('secret jam', (string) $secretLine->attr('data-search'));
    }

    /**
     * The JSON-LD of a series with 200+ editions lists at most 50 sessions: the coming ones, then the newest past (P26)
     */
    public function testTheJsonLdOfTwoHundredEditionsIsCapped(): void
    {
        $browser = self::createClient();
        $seed = WeeklySeriesSeed::create(self::getContainer());

        $browser->request('GET', '/en/series/' . $seed['slug']);

        self::assertResponseIsSuccessful();
        $series = $this->seriesJsonLd((string) $browser->getResponse()->getContent());
        self::assertIsArray($series['subEvent'] ?? null);
        /** @var list<array{name: string}> $subEvents */
        $subEvents = $series['subEvent'];
        self::assertCount(50, $subEvents);
        self::assertSame('Lantern Weekly Jam · Jam No. 203', $subEvents[49]['name'], 'the last coming one');
        self::assertSame('Lantern Weekly Jam · Jam No. 154', $subEvents[0]['name'], 'then the newest 47 past ones');
    }

    /**
     * "Add my time" (P27): a series pick - signed in, a publicly visible series, unless every dated edition is still to
     * come: a series without editions or with undated ones only offers it too (H13)
     */
    public function testAddMyTimeUnlessEveryDatedEditionIsStillToCome(): void
    {
        $browser = self::createClient();
        $scenario = new SeriesEditionScenario(self::getContainer());
        $started = $scenario->series('Lantern Weekly Jam');
        $scenario->edition($started, 'Jam No. 1', new DateTimeImmutable('-3 days')->format('Y-m-d'));
        $coming = $scenario->series('Moonlit Sprint Cup');
        $scenario->edition($coming, 'Round One', new DateTimeImmutable('+3 days')->format('Y-m-d'));
        $undated = $scenario->series('Moonlit Pier Puzzle Club');
        $scenario->edition($undated, 'Pier Meet 1', null);
        $empty = $scenario->series('Copper Kettle Puzzle Circle');
        $pending = $scenario->series('Harbor Puzzle Evenings', public: false);
        $scenario->edition($pending, 'Evening 1', new DateTimeImmutable('-3 days')->format('Y-m-d'));

        foreach ([$started, $undated, $empty] as $seriesId) {
            $guest = $browser->request('GET', $this->seriesUrl($seriesId));
            self::assertCount(0, $guest->filter('[data-series-add-time]'), 'guests get no "Add my time"');
        }

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        foreach (['an edition has started' => $started, 'undated editions only' => $undated, 'no editions' => $empty] as $case => $seriesId) {
            $crawler = $browser->request('GET', $this->seriesUrl($seriesId));
            self::assertSame('/en/puzzle-add?series=' . $seriesId, $crawler->filter('.ev-detail-actions [data-series-add-time]')->attr('href'), $case);
        }

        self::assertCount(0, $browser->request('GET', $this->seriesUrl($coming))->filter('[data-series-add-time]'), 'every dated edition is still to come');
        self::assertCount(0, $browser->request('GET', $this->seriesUrl($pending))->filter('[data-series-add-time]'), 'not public');
    }

    /**
     * A series without editions is first class (H13): its page says so - no filter bar; "Add my time" is offered, a time
     * of it is a result of the series
     */
    public function testASeriesWithoutEditionsSaysSo(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $seriesId = new SeriesEditionScenario(self::getContainer())->series('Lantern Weekly Jam');

        $crawler = $browser->request('GET', $this->seriesUrl($seriesId));

        self::assertResponseIsSuccessful();
        self::assertSame('No editions yet.', trim($crawler->filter('[data-series-empty] p')->text()));
        self::assertCount(0, $crawler->filter('[data-series-filter-target="bar"]'));
        self::assertCount(1, $crawler->filter('[data-series-add-time]'));
        self::assertCount(0, $crawler->filter('[data-series-past]'));
    }

    private function seriesUrl(string $seriesId): string
    {
        $slug = self::getContainer()->get(Connection::class)->fetchOne('SELECT slug FROM competition_series WHERE id = :id', ['id' => $seriesId]);
        self::assertIsString($slug);

        return '/en/series/' . $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function seriesJsonLd(string $content): array
    {
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $content, $matches);

        foreach ($matches[1] as $json) {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

            if (is_array($decoded) && ($decoded['@type'] ?? null) === 'EventSeries') {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }

        self::fail('The series page emits EventSeries JSON-LD');
    }
}
