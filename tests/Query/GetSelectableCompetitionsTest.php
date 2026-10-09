<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetSelectableCompetitions;
use SpeedPuzzling\Web\Results\SelectableCompetition;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventDetailFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\CompetitionPick;
use SpeedPuzzling\Web\Value\CompetitionPickKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The "Competition / event" picker's default list (docs/features/events-page/high-frequency-series.md "The default
 * list"): one-time events and series, one entry each - never an edition unless it is the current pick or a refused
 * submit's public choice.
 */
final class GetSelectableCompetitionsTest extends KernelTestCase
{
    private GetSelectableCompetitions $query;
    private Connection $database;
    private SeriesEditionScenario $scenario;
    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetSelectableCompetitions::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->scenario = new SeriesEditionScenario(self::getContainer());
        $this->today = self::getContainer()->get(ClockInterface::class)->now();
    }

    public function testNoEditionIsInTheDefaultListOnlyItsSeries(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $editions = [
            $this->scenario->edition($seriesId, 'Jam No. 153', $this->day(-9)),
            $this->scenario->edition($seriesId, 'Jam No. 154', $this->day(-2)),
            $this->scenario->edition($seriesId, 'Jam No. 155', $this->day(5)),
        ];

        $all = $this->query->all();
        $byValue = $this->byValue($all);

        self::assertArrayHasKey('series:' . $seriesId, $byValue);
        self::assertSame(SelectableCompetition::KIND_SERIES, $byValue['series:' . $seriesId]->kind);
        self::assertSame('Lantern Weekly Jam', $byValue['series:' . $seriesId]->name);
        self::assertSame(3, $byValue['series:' . $seriesId]->editionCount);

        foreach ($all as $competition) {
            self::assertNotSame(SelectableCompetition::KIND_EDITION, $competition->kind, 'No edition in the default list: ' . $competition->name);
            self::assertNotContains($competition->id, $editions);
        }

        // The fixtures' series are there as series too, their editions are not
        self::assertArrayHasKey('series:' . EventsPageFixture::SERIES_HARBOR_NIGHTS, $byValue);
        self::assertArrayNotHasKey(EventsPageFixture::EDITION_HARBOR_1, $byValue);
        self::assertArrayNotHasKey('edition:' . EventsPageFixture::EDITION_HARBOR_1, $byValue);
    }

    public function testOneTimeEventsAreOfferedAsBefore(): void
    {
        $byValue = $this->byValue($this->query->all());

        self::assertArrayHasKey(EventDetailFixture::COMPETITION_HILLTOP_WEEKEND, $byValue);
        self::assertSame(SelectableCompetition::KIND_EVENT, $byValue[EventDetailFixture::COMPETITION_HILLTOP_WEEKEND]->kind);
        self::assertNull($byValue[EventDetailFixture::COMPETITION_HILLTOP_WEEKEND]->seriesId);
        self::assertArrayHasKey(EventsPageFixture::COMPETITION_RIVERSIDE_OPEN, $byValue);

        // Waiting for approval, rejected, a draft - not offered
        self::assertArrayNotHasKey(CompetitionFixture::COMPETITION_UNAPPROVED, $byValue);
        self::assertArrayNotHasKey(EventsPageFixture::COMPETITION_GARDEN_SWAP_REJECTED, $byValue);
        self::assertArrayNotHasKey(OrganizationFixture::COMPETITION_DRAFT_NIGHT, $byValue);
    }

    public function testASeriesCarriesItsNextAndLastDateOrNone(): void
    {
        $both = $this->scenario->series('Moonlit Puzzle Sprints');
        $this->scenario->edition($both, 'Sprint 1', $this->day(-20));
        $this->scenario->edition($both, 'Sprint 2', $this->day(-6));
        $this->scenario->edition($both, 'Sprint 3', $this->day(8));

        $upcomingOnly = $this->scenario->series('Harbor Puzzle Club Evenings');
        $this->scenario->edition($upcomingOnly, 'Evening 1', $this->day(12));

        // H13: a series without editions is offered like any other ("No dates yet"), one with undated editions too
        $empty = $this->scenario->series('Copper Kettle Puzzle Cup');
        $undated = $this->scenario->series('Willow Lane Puzzle Nights');
        $this->scenario->edition($undated, 'Summer Special', null);

        $live = $this->scenario->series('Starling Puzzle Afternoons');
        $this->scenario->edition($live, 'Afternoon 1', $this->day(0));

        $byValue = $this->byValue($this->query->all());

        $series = $byValue['series:' . $both];
        self::assertSame('past', $series->eventStatus);
        self::assertSame($this->day(-6), $series->lastDay?->format('Y-m-d'));
        self::assertSame($this->day(8), $series->nextDay?->format('Y-m-d'));

        self::assertSame('upcoming', $byValue['series:' . $upcomingOnly]->eventStatus);
        self::assertNull($byValue['series:' . $upcomingOnly]->lastDay);
        self::assertSame($this->day(12), $byValue['series:' . $upcomingOnly]->nextDay?->format('Y-m-d'));

        self::assertSame('undated', $byValue['series:' . $empty]->eventStatus);
        self::assertSame(0, $byValue['series:' . $empty]->editionCount);
        self::assertNull($byValue['series:' . $empty]->nextDay);
        self::assertNull($byValue['series:' . $empty]->lastDay);

        self::assertSame('undated', $byValue['series:' . $undated]->eventStatus);
        self::assertSame(1, $byValue['series:' . $undated]->editionCount);

        self::assertSame('live', $byValue['series:' . $live]->eventStatus);
    }

    public function testOrderIsLiveThenUndatedOneTimeThenPastThenUpcomingThenSeriesWithoutDates(): void
    {
        $liveEvent = $this->insertOneTimeEvent('Lighthouse Live Puzzle Day', 0);
        $perpetual = $this->insertOneTimeEvent('Ever Open Online Puzzle Room', null);
        $pastEvent = $this->insertOneTimeEvent('Old Quarry Puzzle Race', -10);
        $upcomingEvent = $this->insertOneTimeEvent('Pinecone Puzzle Fair', 20);

        $liveSeries = $this->scenario->series('Starling Puzzle Afternoons');
        $this->scenario->edition($liveSeries, 'Afternoon 1', $this->day(0));

        // A series sorts by its latest past edition - newer than the past one-time event
        $pastSeries = $this->scenario->series('Moonlit Puzzle Sprints');
        $this->scenario->edition($pastSeries, 'Sprint 1', $this->day(-3));
        $this->scenario->edition($pastSeries, 'Sprint 2', $this->day(30));

        $upcomingSeries = $this->scenario->series('Harbor Puzzle Club Evenings');
        $this->scenario->edition($upcomingSeries, 'Evening 1', $this->day(10));

        $noDates = $this->scenario->series('Copper Kettle Puzzle Cup');

        $index = array_flip(array_map(static fn (SelectableCompetition $c): string => $c->pick()->fieldValue(), $this->query->all()));

        // live: one-time and series alike
        self::assertLessThan($index[$perpetual], $index[$liveEvent]);
        self::assertLessThan($index[$perpetual], $index['series:' . $liveSeries]);
        // perpetual one-time events before the past
        self::assertLessThan($index[$pastEvent], $index[$perpetual]);
        // past newest first: the series (-3 days) before the one-time event (-10 days)
        self::assertLessThan($index[$pastEvent], $index['series:' . $pastSeries]);
        // upcoming soonest first, after every past entry
        self::assertLessThan($index['series:' . $upcomingSeries], $index[$pastEvent]);
        self::assertLessThan($index[$upcomingEvent], $index['series:' . $upcomingSeries]);
        // a series without any dated edition closes the list
        self::assertLessThan($index['series:' . $noDates], $index[$upcomingEvent]);
    }

    public function testTheCurrentPickIsOfferedEvenWhenNotPublic(): void
    {
        // A one-time event waiting for approval
        $values = $this->values($this->query->all(CompetitionPick::event(CompetitionFixture::COMPETITION_UNAPPROVED)));
        self::assertCount(1, array_keys($values, CompetitionFixture::COMPETITION_UNAPPROVED, true));

        // A series waiting for approval - a series pick of the edited time
        $pendingSeries = $this->scenario->series('Willow Lane Puzzle Nights', public: false);
        self::assertNotContains('series:' . $pendingSeries, $this->values($this->query->all()));
        $values = $this->values($this->query->all(CompetitionPick::series($pendingSeries)));
        self::assertCount(1, array_keys($values, 'series:' . $pendingSeries, true));

        // A draft edition - an explicit link of the edited time
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $draftEdition = $this->scenario->edition($seriesId, 'Jam No. 160', $this->day(3), draft: true);
        $all = $this->query->all(CompetitionPick::edition($draftEdition));
        $values = $this->values($all);
        self::assertCount(1, array_keys($values, 'edition:' . $draftEdition, true));

        // ... right after its series, carrying it for the optgroup
        $index = array_flip($values);
        self::assertSame($index['series:' . $seriesId] + 1, $index['edition:' . $draftEdition]);
        $edition = $all[$index['edition:' . $draftEdition]];
        self::assertSame(SelectableCompetition::KIND_EDITION, $edition->kind);
        self::assertSame($seriesId, $edition->seriesId);
        self::assertSame('Lantern Weekly Jam', $edition->seriesName);
        self::assertSame('Jam No. 160', $edition->name);

        // Already offered - never twice
        $values = $this->values($this->query->all(CompetitionPick::series($seriesId)));
        self::assertCount(1, array_keys($values, 'series:' . $seriesId, true));
    }

    public function testARefusedSubmitsEditionIsOfferedOnlyWhilePublic(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $public = $this->scenario->edition($seriesId, 'Jam No. 154', $this->day(-2));
        $draft = $this->scenario->edition($seriesId, 'Jam No. 161', $this->day(4), draft: true);

        self::assertContains('edition:' . $public, $this->values($this->query->all(submittedEditionId: $public)));
        self::assertNotContains('edition:' . $draft, $this->values($this->query->all(submittedEditionId: $draft)));
        self::assertNotContains('edition:' . $draft, $this->values($this->query->all(submittedEditionId: 'not-a-uuid')));
    }

    public function testDraftAndPendingSeriesAreNotOffered(): void
    {
        $draft = $this->scenario->series('Quiet Harbor Puzzle Series', draft: true);
        $pending = $this->scenario->series('Willow Lane Puzzle Nights', public: false);

        $values = $this->values($this->query->all());

        self::assertNotContains('series:' . $draft, $values);
        self::assertNotContains('series:' . $pending, $values);
        self::assertNotContains('series:' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT, $values);
        self::assertNotContains('series:' . EventsPageFixture::SERIES_OLD_MILL_REJECTED, $values);
    }

    public function testASeriesCarriesThePublicOrganizationsNames(): void
    {
        $byValue = $this->byValue($this->query->all());

        $series = $byValue['series:' . OrganizationFixture::SERIES_RIVERBEND_VIRTUAL];
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND_NAME, $series->organizationName);
        self::assertSame('RJA', $series->organizationShortName);

        // A draft organization's names are no keywords
        $this->database->executeStatement(
            'UPDATE organization SET is_draft = true WHERE id = :id',
            ['id' => OrganizationFixture::ORGANIZATION_RIVERBEND],
        );

        $series = $this->byValue($this->query->all())['series:' . OrganizationFixture::SERIES_RIVERBEND_VIRTUAL];
        self::assertNull($series->organizationName);
        self::assertNull($series->organizationShortName);
    }

    public function testPublicPickOfADeepLink(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $edition = $this->scenario->edition($seriesId, 'Jam No. 154', $this->day(-2));
        $draftEdition = $this->scenario->edition($seriesId, 'Jam No. 162', $this->day(6), draft: true);

        self::assertSame(CompetitionPickKind::Event, $this->query->publicPick(EventDetailFixture::COMPETITION_HILLTOP_WEEKEND)?->kind);
        self::assertSame('edition:' . $edition, $this->query->publicPick($edition)?->fieldValue());
        self::assertNull($this->query->publicPick($draftEdition));
        self::assertNull($this->query->publicPick(CompetitionFixture::COMPETITION_UNAPPROVED));
        self::assertNull($this->query->publicPick($seriesId), 'A series id is no competition');
        self::assertNull($this->query->publicPick('not-a-uuid'));
    }

    private function day(int $offset): string
    {
        return $this->today->modify(sprintf('%+d days', $offset))->format('Y-m-d');
    }

    private function insertOneTimeEvent(string $name, null|int $dayOffset): string
    {
        $id = Uuid::uuid7()->toString();

        $this->database->executeStatement(
            <<<SQL
            INSERT INTO competition (id, name, is_online, approved_at, date_from, date_to)
            VALUES (:id, :name, true, NOW(), :day, :day)
            SQL,
            ['id' => $id, 'name' => $name, 'day' => $dayOffset !== null ? $this->day($dayOffset) : null],
        );

        return $id;
    }

    /**
     * @param list<SelectableCompetition> $competitions
     * @return array<string, SelectableCompetition>
     */
    private function byValue(array $competitions): array
    {
        $byValue = [];

        foreach ($competitions as $competition) {
            $byValue[$competition->pick()->fieldValue()] = $competition;
        }

        return $byValue;
    }

    /**
     * @param list<SelectableCompetition> $competitions
     * @return list<string>
     */
    private function values(array $competitions): array
    {
        return array_map(static fn (SelectableCompetition $c): string => $c->pick()->fieldValue(), $competitions);
    }
}
