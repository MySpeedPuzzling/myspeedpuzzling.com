<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetEventOccurrences;
use SpeedPuzzling\Web\Results\EventOccurrence;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\EventOccurrenceStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetEventOccurrencesTest extends KernelTestCase
{
    private GetEventOccurrences $query;
    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetEventOccurrences::class);
        $now = self::getContainer()->get(ClockInterface::class)->now();
        $this->today = new DateTimeImmutable($now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'), new DateTimeZone('UTC'));
    }

    public function testThePublicSetHasNoUnapprovedRejectedOrRejectedSeriesItems(): void
    {
        $ids = array_map(static fn (EventOccurrence $occurrence): string => $occurrence->competitionId, $this->query->all(false));

        self::assertContains(EventsPageFixture::COMPETITION_RIVERSIDE_OPEN, $ids);
        self::assertContains(EventsPageFixture::EDITION_HARBOR_1, $ids);
        self::assertContains(CompetitionFixture::COMPETITION_WJPC_2024, $ids);
        self::assertNotContains(CompetitionFixture::COMPETITION_UNAPPROVED, $ids);
        self::assertNotContains(CompetitionSeriesFixture::EDITION_UNAPPROVED_1, $ids);
        self::assertNotContains(EventsPageFixture::COMPETITION_GARDEN_SWAP_REJECTED, $ids);
        // Old Mill: approved, then rejected - its edition is gone with it
        self::assertNotContains(EventsPageFixture::EDITION_OLD_MILL, $ids);

        foreach ($this->query->all(false) as $occurrence) {
            self::assertTrue($occurrence->isPublic);
        }
    }

    public function testAdminsAlsoGetTheOnesWaitingForApproval(): void
    {
        $occurrences = $this->byId($this->query->all(true));

        self::assertArrayHasKey(CompetitionFixture::COMPETITION_UNAPPROVED, $occurrences);
        self::assertFalse($occurrences[CompetitionFixture::COMPETITION_UNAPPROVED]->isPublic);
        self::assertArrayHasKey(CompetitionSeriesFixture::EDITION_UNAPPROVED_1, $occurrences);
        self::assertFalse($occurrences[CompetitionSeriesFixture::EDITION_UNAPPROVED_1]->isPublic);
        self::assertTrue($occurrences[EventsPageFixture::COMPETITION_RIVERSIDE_OPEN]->isPublic);
        self::assertArrayNotHasKey(EventsPageFixture::COMPETITION_GARDEN_SWAP_REJECTED, $occurrences);
        self::assertArrayNotHasKey(EventsPageFixture::EDITION_OLD_MILL, $occurrences);
    }

    /**
     * Session 1's round starts at 23:30 in Toronto - in UTC that is already the next day
     */
    public function testAnEditionIsDatedByItsFirstRoundInTheEventsZone(): void
    {
        $occurrences = $this->byId($this->query->all(false));
        $session = $occurrences[EventsPageFixture::EDITION_HARBOR_1];
        $expected = $this->today->modify('first day of +2 months')->modify('+4 days');

        self::assertEquals($expected, $session->startDate);
        self::assertNull($session->endDate);
        self::assertSame(1, $session->roundCount);
        self::assertTrue($session->isOnline);
        self::assertSame(CountryCode::ca, $session->countryCode);
        self::assertSame(EventsPageFixture::SERIES_HARBOR_NIGHTS_NAME, $session->seriesName);
        self::assertSame('Session 1', $session->editionName());
        self::assertSame(EventOccurrenceStatus::Upcoming, $session->status($this->today));
    }

    public function testStatuses(): void
    {
        $occurrences = $this->byId($this->query->all(false));

        $clock = $occurrences[EventsPageFixture::EDITION_CLOCK_LONG];
        self::assertSame(EventOccurrenceStatus::Live, $clock->status($this->today));
        self::assertTrue($clock->isLongRunning());
        self::assertNull($clock->editionName(), 'named like its series');

        self::assertSame(EventOccurrenceStatus::Tba, $occurrences[EventsPageFixture::COMPETITION_MEADOW_TBA]->status($this->today));
        self::assertTrue($occurrences[EventsPageFixture::COMPETITION_MEADOW_TBA]->hasRegistrationLink);
        self::assertSame(EventOccurrenceStatus::Ongoing, $occurrences[EventsPageFixture::COMPETITION_ENDLESS_RELAY]->status($this->today));
        self::assertSame(EventOccurrenceStatus::DateNotSet, $occurrences[EventsPageFixture::EDITION_HARBOR_UNDATED]->status($this->today));
        self::assertSame(EventOccurrenceStatus::Past, $occurrences[EventsPageFixture::COMPETITION_VALLEY_CUP_LAST_YEAR]->status($this->today));
        self::assertSame(EventOccurrenceStatus::Upcoming, $occurrences[EventsPageFixture::COMPETITION_RIVERSIDE_OPEN]->status($this->today));
    }

    public function testResults(): void
    {
        $occurrences = $this->byId($this->query->all(false));

        self::assertTrue($occurrences[EventsPageFixture::COMPETITION_VALLEY_CUP_LAST_YEAR]->hasResults);
        self::assertFalse($occurrences[EventsPageFixture::COMPETITION_VALLEY_CUP_TWO_YEARS_AGO]->hasResults);
    }

    public function testManagedRegistration(): void
    {
        $riverside = $this->byId($this->query->all(false))[EventsPageFixture::COMPETITION_RIVERSIDE_OPEN];

        self::assertTrue($riverside->registrationManaged);
        self::assertFalse($riverside->hasRegistrationLink);
        self::assertSame(2, $riverside->capacity);
        self::assertSame('Europe/Berlin', $riverside->registrationTimezone);
        self::assertSame('Hamburg', $riverside->location);
    }

    public function testOrderedByStartUndatedLast(): void
    {
        $occurrences = $this->query->all(false);
        $seenUndated = false;
        $previous = null;

        foreach ($occurrences as $occurrence) {
            if ($occurrence->startDate === null) {
                $seenUndated = true;

                continue;
            }

            self::assertFalse($seenUndated, 'dated after undated: ' . $occurrence->name);

            if ($previous !== null) {
                self::assertGreaterThanOrEqual($previous, $occurrence->startDate);
            }

            $previous = $occurrence->startDate;
        }
    }

    /**
     * @param list<EventOccurrence> $occurrences
     *
     * @return array<string, EventOccurrence>
     */
    private function byId(array $occurrences): array
    {
        $byId = [];

        foreach ($occurrences as $occurrence) {
            $byId[$occurrence->competitionId] = $occurrence;
        }

        return $byId;
    }
}
