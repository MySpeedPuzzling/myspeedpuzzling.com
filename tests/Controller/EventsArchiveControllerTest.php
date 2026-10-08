<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetCompetitionSlugsForSitemap;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The year archive `events_archive` and the SEO rules of the events pages (docs/features/events-page/README.md, "SEO"):
 * one indexable page per year with past events, the events page's roll-up lines, every year linked, an ItemList of
 * every occurrence of the year, `noindex, follow` on any query string.
 */
final class EventsArchiveControllerTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLocalizedPaths(): iterable
    {
        yield 'cs' => ['/eventy/archiv/'];
        yield 'en' => ['/en/events/archive/'];
        yield 'es' => ['/es/eventos/archivo/'];
        yield 'ja' => ['/ja/イベント/アーカイブ/'];
        yield 'fr' => ['/fr/evenements/archives/'];
        yield 'de' => ['/de/veranstaltungen/archiv/'];
    }

    #[DataProvider('provideLocalizedPaths')]
    public function testLastYearRendersInEveryLocale(string $path): void
    {
        $browser = self::createClient();

        $browser->request('GET', $path . $this->lastYear());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', (string) $this->lastYear());
        self::assertSelectorTextContains('main', EventsPageFixture::COMPETITION_VALLEY_CUP_NAME . ' ' . $this->lastYear());
    }

    public function testLastYearListsItsEventsWithTheSeriesRolledUp(): void
    {
        $browser = self::createClient();
        $lastYear = $this->lastYear();

        $crawler = $browser->request('GET', '/en/events/archive/' . $lastYear);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Events in ' . $lastYear);
        self::assertSelectorTextContains('title', 'Speed puzzling events ' . $lastYear . ' - results and archive');

        // Harbor's two editions of last year are one line linking the series page
        $rollUp = $crawler->filter('.ev-archive-lines a[href="/en/series/harbor-jigsaw-nights"]');
        self::assertCount(1, $rollUp);
        self::assertSame(EventsPageFixture::SERIES_HARBOR_NIGHTS_NAME . ' · 2 editions in ' . $lastYear, trim($rollUp->text()));
        self::assertCount(0, $crawler->filter('.ev-archive-lines a[href="/en/series/harbor-jigsaw-nights/spring-session"]'));

        // Valley Cup: a line of its own with its results tag
        $valley = $crawler->filter('.ev-archive-lines .ev-line')->reduce(
            static fn (Crawler $line): bool => str_contains($line->text(), EventsPageFixture::COMPETITION_VALLEY_CUP_NAME . ' ' . $lastYear),
        );
        self::assertCount(1, $valley);
        self::assertSame('/en/events/valley-speed-puzzle-cup-' . $lastYear, $valley->filter('a')->attr('href'));
        self::assertStringContainsString('Results', $valley->text());

        // Nothing of another year, nothing upcoming
        self::assertStringNotContainsString(EventsPageFixture::COMPETITION_VALLEY_CUP_NAME . ' ' . ($lastYear - 1), $crawler->filter('main')->text());
        self::assertStringNotContainsString(EventsPageFixture::COMPETITION_RIVERSIDE_OPEN_NAME, $crawler->filter('main')->text());

        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertStringEndsWith('/en/events/archive/' . $lastYear, (string) $crawler->filter('link[rel="canonical"]')->attr('href'));
    }

    public function testTwoYearsAgoRenders(): void
    {
        $browser = self::createClient();
        $year = $this->lastYear() - 1;

        $browser->request('GET', '/en/events/archive/' . $year);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', EventsPageFixture::COMPETITION_VALLEY_CUP_NAME . ' ' . $year);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideYearsWithoutEvents(): iterable
    {
        yield '1999' => ['1999'];
        yield 'year zero' => ['0000'];
        yield 'far future' => ['9999'];
    }

    #[DataProvider('provideYearsWithoutEvents')]
    public function testYearWithoutPastEventsIsNotFound(string $year): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/events/archive/' . $year);

        self::assertResponseStatusCodeSame(404);
    }

    public function testNextYearIsNotFound(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/events/archive/' . ($this->lastYear() + 2));

        self::assertResponseStatusCodeSame(404);
    }

    public function testOnlyFourDigitYearsMatchTheRoute(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/events/archive/25');
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/events/archive/abcd');
        self::assertResponseStatusCodeSame(404);
    }

    public function testEveryArchiveYearIsLinkedWithPreviousAndNext(): void
    {
        $browser = self::createClient();
        $years = self::getContainer()->get(GetCompetitionSlugsForSitemap::class)->archiveYears();
        $lastYear = $this->lastYear();
        $position = array_search($lastYear, $years, true);
        self::assertIsInt($position);

        $crawler = $browser->request('GET', '/en/events/archive/' . $lastYear);

        // The page's years = the sitemap's years, newest first
        $linked = $crawler->filter('.ev-archive-year')->each(static fn (Crawler $link): int => (int) $link->text());
        self::assertSame($years, $linked);
        self::assertSame((string) $lastYear, $crawler->filter('.ev-archive-year[aria-current="page"]')->text());

        $previous = $crawler->filter('a[rel="prev"]');
        self::assertCount(1, $previous);
        self::assertSame('/en/events/archive/' . $years[$position + 1], $previous->attr('href'));

        $next = $crawler->filter('a[rel="next"]');

        if ($position === 0) {
            self::assertCount(0, $next);
        } else {
            self::assertSame('/en/events/archive/' . $years[$position - 1], $next->attr('href'));
        }

        self::assertCount(1, $crawler->filter('.ev-archive-pager a[href="/en/events"]'));
    }

    public function testEveryYearTheSitemapListsAnswers(): void
    {
        $browser = self::createClient();

        foreach (self::getContainer()->get(GetCompetitionSlugsForSitemap::class)->archiveYears() as $year) {
            $browser->request('GET', '/en/events/archive/' . $year);
            self::assertResponseIsSuccessful('Archive year ' . $year);
        }
    }

    public function testItemListHoldsEveryOccurrenceOfTheYear(): void
    {
        $browser = self::createClient();
        $lastYear = $this->lastYear();

        $crawler = $browser->request('GET', '/en/events/archive/' . $lastYear);

        $itemList = $this->itemList($crawler);
        self::assertNotNull($itemList);
        self::assertSame('https://schema.org', $itemList['@context']);
        self::assertIsArray($itemList['itemListElement']);
        self::assertSame(count($itemList['itemListElement']), $itemList['numberOfItems']);

        $urls = [];

        foreach ($itemList['itemListElement'] as $index => $item) {
            self::assertIsArray($item);
            self::assertSame('ListItem', $item['@type']);
            self::assertSame($index + 1, $item['position']);
            self::assertIsString($item['url']);
            $urls[] = $item['url'];
        }

        self::assertContains('http://localhost/en/events/valley-speed-puzzle-cup-' . $lastYear, $urls);
        // Rolled-up editions are listed one by one
        self::assertContains('http://localhost/en/series/harbor-jigsaw-nights/spring-session', $urls);
        self::assertContains('http://localhost/en/series/harbor-jigsaw-nights/summer-session', $urls);
        self::assertNotContains('http://localhost/en/events/valley-speed-puzzle-cup-' . ($lastYear - 1), $urls);
    }

    public function testAnyQueryStringIsNotIndexed(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/events/archive/' . $this->lastYear() . '?utm_source=newsletter');

        self::assertResponseIsSuccessful();
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertStringEndsWith('/en/events/archive/' . $this->lastYear(), (string) $crawler->filter('link[rel="canonical"]')->attr('href'));
    }

    public function testEventsPageIsIndexedOnlyOnTheBareUrl(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/events');
        self::assertResponseIsSuccessful();
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertCount(1, $crawler->filter('meta[name="robots"]'));

        foreach (['?country=cz', '?onlineOnly=1', '?view=calendar', '?q=valley', '?view=calendar&month=2026-11'] as $query) {
            $crawler = $browser->request('GET', '/en/events' . $query);
            self::assertResponseIsSuccessful($query);
            self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'), $query);
            self::assertStringEndsWith('/en/events', (string) $crawler->filter('link[rel="canonical"]')->attr('href'), $query);
        }
    }

    public function testEventsPageItemListHoldsTheComingOccurrences(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/events');

        $itemList = $this->itemList($crawler);
        self::assertNotNull($itemList);
        self::assertIsArray($itemList['itemListElement']);
        $urls = array_map(static fn (mixed $item): mixed => is_array($item) ? $item['url'] : null, $itemList['itemListElement']);

        self::assertContains('http://localhost/en/events/riverside-puzzle-open', $urls);
        self::assertNotContains('http://localhost/en/events/valley-speed-puzzle-cup-' . $this->lastYear(), $urls);
    }

    private function lastYear(): int
    {
        return (int) self::getContainer()->get(ClockInterface::class)->now()->format('Y') - 1;
    }

    /**
     * @return null|array<string, mixed>
     */
    private function itemList(Crawler $crawler): null|array
    {
        foreach ($crawler->filter('script[type="application/ld+json"]') as $script) {
            $data = json_decode((string) $script->textContent, true, flags: JSON_THROW_ON_ERROR);

            if (is_array($data) && ($data['@type'] ?? null) === 'ItemList') {
                /** @var array<string, mixed> $data */
                return $data;
            }
        }

        return null;
    }
}
