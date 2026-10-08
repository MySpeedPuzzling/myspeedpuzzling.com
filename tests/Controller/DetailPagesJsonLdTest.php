<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\EventDetailFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The structured data of the series, edition and event pages (docs/features/events-page/detail-pages.md "SEO"): one
 * Event / EventSeries per page, `subEvent` one per session, every value through `json_ld`.
 */
final class DetailPagesJsonLdTest extends WebTestCase
{
    private const string SPRINT_LEAGUE = '/en/series/moonlight-sprint-league';
    private const string SEASON_ONE = '/en/series/moonlight-sprint-league/season-one';
    private const string HARBOR = '/en/series/harbor-jigsaw-nights';
    private const string HILLTOP = '/en/events/' . EventDetailFixture::COMPETITION_HILLTOP_WEEKEND_SLUG;

    /**
     * @return iterable<string, array{string}>
     */
    public static function providePages(): iterable
    {
        yield 'series with sessions' => [self::SPRINT_LEAGUE];
        yield 'series with an undated edition' => [self::HARBOR];
        yield 'edition with sessions' => [self::SEASON_ONE];
        yield 'multi-day event' => [self::HILLTOP];
    }

    #[DataProvider('providePages')]
    public function testEveryJsonLdBlockParses(string $url): void
    {
        $browser = self::createClient();

        $blocks = self::jsonLdBlocks($browser, $url);

        self::assertNotSame([], $blocks);
    }

    public function testEditionWithARoundAMonthHasASubEventPerSession(): void
    {
        $browser = self::createClient();
        $roundDays = EventsPageFixture::storedSprintRoundDays(self::getContainer()->get(Connection::class));

        $event = self::single('Event', self::jsonLdBlocks($browser, self::SEASON_ONE));

        self::assertSame($roundDays[0], $event['startDate'] ?? null);
        self::assertSame($roundDays[3], $event['endDate'] ?? null);
        self::assertIsArray($event['subEvent'] ?? null);
        self::assertCount(4, $event['subEvent']);

        $roundIds = array_keys(EventsPageFixture::SPRINT_ROUND_DAYS);
        $names = [];

        foreach (array_values($event['subEvent']) as $index => $subEvent) {
            self::assertIsArray($subEvent);
            self::assertSame('Event', $subEvent['@type'] ?? null);
            self::assertIsString($subEvent['url'] ?? null);
            self::assertStringEndsWith(self::SEASON_ONE . '#round-' . $roundIds[$index], $subEvent['url']);
            self::assertSame($roundDays[$index], $subEvent['startDate'] ?? null);
            self::assertArrayNotHasKey('endDate', $subEvent, 'A one-day session has no end date');
            self::assertSame('https://schema.org/OnlineEventAttendanceMode', $subEvent['eventAttendanceMode'] ?? null);
            $names[] = $subEvent['name'] ?? null;
        }

        // Named by the session's one round
        self::assertSame('Moonlight Sprint League · Season One · Sprint 3', $names[2]);
    }

    public function testEditionDatedOnlyByItsRoundsGetsItsEvent(): void
    {
        $browser = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'UPDATE competition SET date_from = NULL, date_to = NULL WHERE id = :id',
            ['id' => EventsPageFixture::EDITION_SPRINT_SEASON],
        );
        $roundDays = EventsPageFixture::storedSprintRoundDays($connection);

        $event = self::single('Event', self::jsonLdBlocks($browser, self::SEASON_ONE));

        self::assertSame($roundDays[0], $event['startDate'] ?? null);
        self::assertSame($roundDays[3], $event['endDate'] ?? null);
    }

    public function testMultiDayChampionshipIsOneEventWithoutSubEvents(): void
    {
        $browser = self::createClient();

        $event = self::single('Event', self::jsonLdBlocks($browser, self::HILLTOP));

        self::assertSame(EventDetailFixture::COMPETITION_HILLTOP_WEEKEND_NAME, $event['name'] ?? null);
        self::assertArrayNotHasKey('subEvent', $event);
        self::assertSame('https://schema.org/OfflineEventAttendanceMode', $event['eventAttendanceMode'] ?? null);
        self::assertIsString($event['startDate'] ?? null);
        self::assertIsString($event['endDate'] ?? null);
        // Friday to Sunday
        self::assertSame('+2 days', new DateTimeImmutable($event['startDate'])->diff(new DateTimeImmutable($event['endDate']))->format('%R%a days'));
    }

    public function testSeriesListsEverySessionOfItsEditions(): void
    {
        $browser = self::createClient();
        $roundDays = EventsPageFixture::storedSprintRoundDays(self::getContainer()->get(Connection::class));

        $series = self::single('EventSeries', self::jsonLdBlocks($browser, self::SPRINT_LEAGUE));

        self::assertIsArray($series['subEvent'] ?? null);
        self::assertCount(4, $series['subEvent']);

        $roundIds = array_keys(EventsPageFixture::SPRINT_ROUND_DAYS);

        foreach (array_values($series['subEvent']) as $index => $subEvent) {
            self::assertIsArray($subEvent);
            self::assertIsString($subEvent['url'] ?? null);
            self::assertStringEndsWith(self::SEASON_ONE . '#round-' . $roundIds[$index], $subEvent['url']);
            self::assertSame($roundDays[$index], $subEvent['startDate'] ?? null);
        }
    }

    public function testSeriesLeavesOutTheUndatedEdition(): void
    {
        $browser = self::createClient();

        $series = self::single('EventSeries', self::jsonLdBlocks($browser, self::HARBOR));

        self::assertIsArray($series['subEvent'] ?? null);
        // Three upcoming sessions and last year's two - not "Session to be planned"
        self::assertCount(5, $series['subEvent']);

        foreach ($series['subEvent'] as $subEvent) {
            self::assertIsArray($subEvent);
            self::assertIsString($subEvent['name'] ?? null);
            self::assertStringNotContainsString('Session to be planned', $subEvent['name']);
            self::assertIsString($subEvent['startDate'] ?? null);
        }
    }

    public function testNameClosingTheScriptElementStaysInsideItsString(): void
    {
        $browser = self::createClient();
        $name = 'Hilltop </script><script>alert(1)</script> Weekend';
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition SET name = :name WHERE id = :id',
            ['name' => $name, 'id' => EventDetailFixture::COMPETITION_HILLTOP_WEEKEND],
        );

        $event = self::single('Event', self::jsonLdBlocks($browser, self::HILLTOP));

        self::assertSame($name, $event['name'] ?? null);
        self::assertStringNotContainsString('<script>alert(1)', (string) $browser->getResponse()->getContent());
    }

    public function testNonPublicPagesHaveNoEventJsonLd(): void
    {
        $browser = self::createClient();

        // An unapproved one-time event (CompetitionFixture::COMPETITION_UNAPPROVED) and an edition of a rejected series
        foreach (['/en/events/unapproved-puzzle-event', '/en/series/old-mill-puzzle-nights/old-mill-night-1'] as $url) {
            $types = array_map(static fn (array $block): mixed => $block['@type'] ?? null, self::jsonLdBlocks($browser, $url));

            self::assertNotContains('Event', $types, $url);
        }
    }

    /**
     * Every JSON-LD block of the page, decoded (a block that does not parse fails the test)
     *
     * @return list<array<mixed>>
     */
    private static function jsonLdBlocks(KernelBrowser $browser, string $url): array
    {
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', (string) $browser->getResponse()->getContent(), $matches);

        $blocks = [];

        foreach ($matches[1] as $json) {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $blocks[] = $decoded;
        }

        return $blocks;
    }

    /**
     * @param list<array<mixed>> $blocks
     *
     * @return array<mixed>
     */
    private static function single(string $type, array $blocks): array
    {
        $found = array_values(array_filter($blocks, static fn (array $block): bool => ($block['@type'] ?? null) === $type));

        self::assertCount(1, $found, sprintf('The page emits one %s JSON-LD', $type));

        return $found[0];
    }
}
