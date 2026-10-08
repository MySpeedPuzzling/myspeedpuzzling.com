<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A day typed into an event's date field is that day everywhere after the picker: stored as typed, shown as that
 * day (with its weekday) on the event pages and the events page, and kept by a save of the edit form. An organiser's
 * Monday and Tuesday bar nights were saved a day late - the picker's calendar started on Monday for an American
 * visitor (tests/DatePickerScriptTest.php); this pins that nothing behind the picker moves a day.
 */
final class EventDayRoundTripTest extends WebTestCase
{
    private const string SERIES_URL = '/en/series/puzzle-meetup-prague';

    public function testAMondayEditionIsThatMondayFromTheFormToItsPagesAndBack(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $edition = self::addEdition($browser, 'Lantern Brewing Puzzle Night - October', '05.10.2026');
        self::assertSame('2026-10-05 00:00:00', $edition['date_from']);
        self::assertSame('2026-10-05 00:00:00', $edition['date_to']);

        $crawler = $browser->request('GET', self::SERIES_URL . '/' . $edition['slug']);
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('5 Oct 2026', $crawler->filter('.ev-detail-side')->text());
        self::assertStringContainsString('"startDate": "2026-10-05"', (string) $browser->getResponse()->getContent());

        // The series page's past: one archive line (the events archive's line) under 2026
        $crawler = $browser->request('GET', self::SERIES_URL);
        $this->assertResponseIsSuccessful();
        $line = $crawler->filter('#series-past-2026 .ev-line')->reduce(static fn ($node): bool => str_contains($node->text(), 'Lantern Brewing Puzzle Night'));
        self::assertCount(1, $line);
        self::assertSame('5 Oct', $line->filter('.ev-line-date')->text());

        // The edit form shows the stored day and a save without touching it keeps it
        $crawler = $browser->request('GET', '/en/edit-event/' . $edition['id']);
        $this->assertResponseIsSuccessful();
        self::assertSame('05.10.2026', $crawler->filter('input[name="competition_form[dateFrom]"]')->attr('value'));
        self::assertSame('05.10.2026', $crawler->filter('input[name="competition_form[dateTo]"]')->attr('value'));

        $browser->submitForm('Save Changes');
        $this->assertResponseRedirects();
        $saved = self::edition('Lantern Brewing Puzzle Night - October');
        self::assertSame('2026-10-05 00:00:00', $saved['date_from']);
        self::assertSame('2026-10-05 00:00:00', $saved['date_to']);
    }

    /**
     * An upcoming Monday edition on the events page: its agenda row, the day's leaf and the browser's index entry
     */
    public function testAnUpcomingMondayEditionIsThatMondayOnTheEventsPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $year = (int) self::getContainer()->get(ClockInterface::class)->now()->format('Y') + 1;
        $monday = new DateTimeImmutable(sprintf('first monday of october %d', $year));

        $edition = self::addEdition($browser, 'Harbor Puzzle Club Bar Night', $monday->format('d.m.Y'));
        self::assertSame($monday->format('Y-m-d') . ' 00:00:00', $edition['date_from']);

        $browser->request('GET', '/en/events');
        $this->assertResponseIsSuccessful();
        $html = (string) $browser->getResponse()->getContent();
        $crawler = $browser->getCrawler();

        $row = $crawler->filter('.ev-row')->reduce(static fn ($node): bool => str_contains($node->text(), 'Harbor Puzzle Club Bar Night'));
        self::assertGreaterThanOrEqual(1, $row->count());
        self::assertSame($monday->format('Y-m-d'), $row->first()->attr('data-ev-from'));
        self::assertStringContainsString($monday->format('l, j F Y'), $row->first()->filter('.visually-hidden')->text());
        self::assertSame('Mon', $row->first()->filter('.ev-leaf-band')->text());
        self::assertSame($monday->format('j'), $row->first()->filter('.ev-leaf-day')->text());
        // The browser's index (search, calendar) carries the same day
        self::assertStringContainsString('"f":"' . $monday->format('Y-m-d') . '"', $html);
    }

    /**
     * A one-time event from the add form: stored as typed, shown as that day on its page
     */
    public function testAMondayEventIsThatMondayFromTheAddFormToItsPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/add-event');
        $browser->submitForm('Submit for Approval', [
            'competition_form[name]' => 'Riverbend Jigsaw Association Monday Meetup',
            'competition_form[isOnline]' => '0',
            'competition_form[location]' => 'Riverbend',
            'competition_form[dateFrom]' => '05.10.2026',
            'competition_form[dateTo]' => '05.10.2026',
        ]);
        $this->assertResponseRedirects();

        /** @var false|array{slug: string, date_from: string, date_to: string} $event */
        $event = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT slug, date_from, date_to FROM competition WHERE name = :name',
            ['name' => 'Riverbend Jigsaw Association Monday Meetup'],
        );
        self::assertIsArray($event);
        self::assertSame('2026-10-05 00:00:00', $event['date_from']);
        self::assertSame('2026-10-05 00:00:00', $event['date_to']);

        // Its creator sees it while it waits for approval
        $crawler = $browser->request('GET', '/en/events/' . $event['slug']);
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('5 Oct 2026', $crawler->filter('.ev-detail-side')->text());
    }

    /**
     * @return array{id: string, slug: string, date_from: string, date_to: string}
     */
    private static function addEdition(KernelBrowser $browser, string $name, string $day): array
    {
        $browser->request('GET', '/en/add-edition/' . CompetitionSeriesFixture::SERIES_OFFLINE);
        $browser->submitForm('Add Edition', [
            'edition_form[name]' => $name,
            'edition_form[dateFrom]' => $day,
            'edition_form[dateTo]' => $day,
        ]);
        self::assertResponseRedirects();

        return self::edition($name);
    }

    /**
     * @return array{id: string, slug: string, date_from: string, date_to: string}
     */
    private static function edition(string $name): array
    {
        /** @var false|array{id: string, slug: string, date_from: string, date_to: string} $row */
        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT id, slug, date_from, date_to FROM competition WHERE series_id = :seriesId AND name = :name',
            ['seriesId' => CompetitionSeriesFixture::SERIES_OFFLINE, 'name' => $name],
        );
        self::assertIsArray($row);

        return $row;
    }
}
