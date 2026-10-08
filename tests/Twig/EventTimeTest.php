<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Twig;

use DateTimeImmutable;
use DateTimeZone;
use IntlTimeZone;
use SpeedPuzzling\Web\Query\GetEditionRounds;
use SpeedPuzzling\Web\Results\EditionRoundDetail;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Value\EventTime;
use SpeedPuzzling\Web\Value\RoundTimezone;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Environment;

/**
 * A start time in the event's zone with the zone named - event_time() and event_parts/_event_time.html.twig
 * (docs/features/events-page/detail-pages.md "Times and time zones"): 24 hours, the zone's localised generic name.
 */
final class EventTimeTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get(Environment::class);
    }

    public function testASprintRoundOnAnEnglishPage(): void
    {
        self::assertSame('22:00 Eastern Time', $this->eventTime($this->sprintRound(), 'en'));
    }

    public function testTheZoneIsNamedInThePagesLanguage(): void
    {
        $german = IntlTimeZone::createTimeZone('America/New_York')->getDisplayName(false, IntlTimeZone::DISPLAY_LONG_GENERIC, 'de');

        self::assertSame('22:00 ' . $german, $this->eventTime($this->sprintRound(), 'de'));
        self::assertSame('Nordamerikanische Ostküstenzeit', $german);
    }

    public function testAnAssumedZoneIsNamedWithoutAPlace(): void
    {
        $time = new EventTime(new DateTimeImmutable('2026-06-17 16:00', new DateTimeZone('UTC')), RoundTimezone::FALLBACK, true);

        self::assertSame('18:00 Central European Time', $this->eventTime($time, 'en'));
    }

    public function testHoursHaveTwoDigitsInEveryLanguage(): void
    {
        $time = new EventTime(new DateTimeImmutable('2026-06-17 02:05', new DateTimeZone('UTC')), 'Europe/Prague');

        foreach (['en', 'cs', 'de', 'es', 'fr', 'ja'] as $locale) {
            self::assertStringStartsWith('04:05', $this->eventTime($time, $locale), $locale);
        }
    }

    public function testThePartialMarksTheInstantAndAddsTheVisitorsSlotOnlineOnly(): void
    {
        $this->switchLocale('en');
        $time = EventTime::fromRound($this->sprintRound());

        $online = $this->twig->render('event_parts/_event_time.html.twig', ['time' => $time, 'online' => true]);
        $inPerson = $this->twig->render('event_parts/_event_time.html.twig', ['time' => $time, 'online' => false]);

        self::assertStringContainsString(sprintf('<time datetime="%s" data-event-time data-event-zone="America/New_York">22:00</time>', $time->isoInstant()), $online);
        self::assertStringContainsString('<span class="ev-time-zone" data-round-zone>Eastern Time</span>', $online);
        self::assertStringContainsString('<span class="ev-time-yours" data-local-time hidden></span>', $online);
        self::assertStringNotContainsString('data-local-time', $inPerson);
        self::assertStringEndsWith('Z', $time->isoInstant());
    }

    private function eventTime(EditionRoundDetail|EventTime $time, string $locale): string
    {
        $this->switchLocale($locale);

        return $this->twig->createTemplate('{{ event_time(time) }}')->render([
            'time' => $time instanceof EventTime ? $time : EventTime::fromRound($time),
        ]);
    }

    private function switchLocale(string $locale): void
    {
        self::getContainer()->get(LocaleSwitcher::class)->setLocale($locale);
    }

    /**
     * Moonlight Sprint League, Sprint 3 - 22:00 in New York
     */
    private function sprintRound(): EditionRoundDetail
    {
        foreach (self::getContainer()->get(GetEditionRounds::class)->forCompetition(EventsPageFixture::EDITION_SPRINT_SEASON) as $round) {
            if ($round->id === EventsPageFixture::ROUND_SPRINT_3) {
                return $round;
            }
        }

        self::fail('Sprint 3 is missing');
    }
}
