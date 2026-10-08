<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\EventDetail;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\CompetitionReference;
use SpeedPuzzling\Web\Results\EditionRoundDetail;
use SpeedPuzzling\Web\Results\EditionRoundPuzzle;
use SpeedPuzzling\Web\Results\EventDetail\RoundsTimeline;
use SpeedPuzzling\Web\Results\EventDetail\TimelineRound;
use SpeedPuzzling\Web\Results\EventsPage\DateLeaf;
use SpeedPuzzling\Web\Results\EventsPage\WhenLabel;
use SpeedPuzzling\Web\Services\EventDetail\RoundsTimelineBuilder;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundStatus;

/**
 * The rounds timeline's rules (detail-pages-plan.md 1.3): past / live / next / later, folding, the when label by local
 * days, the links of a round and the page's dates. Synthetic rounds, a fixed "now".
 */
final class RoundsTimelineBuilderTest extends TestCase
{
    // Wednesday, 14:00 in New York
    private const string NOW = '2026-06-10 18:00:00';

    public function testStatusesFoldingAndTheNextRound(): void
    {
        $rounds = [
            self::round('r1', '2026-03-11 22:00'),
            self::round('r2', '2026-04-08 22:00'),
            self::round('r3', '2026-05-13 22:00'),
            self::round('r4', '2026-06-17 22:00'),
            self::round('r5', '2026-07-15 22:00'),
        ];

        $timeline = $this->build($rounds);

        self::assertSame([RoundStatus::Past, RoundStatus::Past, RoundStatus::Past, RoundStatus::Next, RoundStatus::Later], self::statuses($timeline));
        self::assertSame('r4', $timeline->nextRoundId);
        // Past rounds before the latest past one fold - r3 stays in sight
        self::assertSame([true, true, false, false, false], array_map(static fn (TimelineRound $round): bool => $round->folded, $timeline->rounds));
        self::assertSame(2, $timeline->foldedCount);
        self::assertSame(['r1', 'r2'], array_map(static fn (TimelineRound $round): string => $round->round->id, $timeline->foldedRounds()));
        self::assertSame(WhenLabel::IN_DAYS, $timeline->rounds[3]->when?->type);
        self::assertSame(7, $timeline->rounds[3]->when->days);
        self::assertNull($timeline->rounds[4]->when);
        self::assertNull($timeline->rounds[0]->when);
        self::assertSame(DateLeaf::TONE_MUTED, $timeline->rounds[0]->leaf->tone);
        self::assertSame(DateLeaf::TONE_ONLINE, $timeline->rounds[3]->leaf->tone);
        // 22:00 in New York is the next day in UTC - the leaf shows the local day
        self::assertSame('2026-06-17', $timeline->rounds[3]->leaf->from?->format('Y-m-d'));
        self::assertSame('2026-06-18T02:00:00Z', $timeline->rounds[3]->time->isoInstant());

        // Sessions and the page's dates: one session per round day
        self::assertCount(5, $timeline->sessions);
        self::assertSame('2026-03-11', $timeline->start?->format('Y-m-d'));
        self::assertSame('2026-07-15', $timeline->end?->format('Y-m-d'));
        self::assertFalse($timeline->isLive);
        self::assertSame('America/New_York', $timeline->zone);
    }

    public function testARunningRoundIsLiveAndCountsAsTheNext(): void
    {
        $timeline = $this->build([
            self::round('r1', '2026-06-10 13:30', minutes: 60, zone: 'America/New_York'),
            self::round('r2', '2026-06-10 13:00', minutes: 90, zone: 'America/New_York'),
            self::round('r3', '2026-06-10 16:00', minutes: 60, zone: 'America/New_York'),
        ]);

        self::assertSame([RoundStatus::Live, RoundStatus::Live, RoundStatus::Later], self::statuses($timeline));
        self::assertSame('r1', $timeline->nextRoundId);
        self::assertSame(WhenLabel::LIVE, $timeline->rounds[0]->when?->type);
        self::assertTrue($timeline->isLive);
        self::assertSame(0, $timeline->foldedCount);
    }

    public function testANextRoundLaterTodaySaysToday(): void
    {
        $timeline = $this->build([
            self::round('r1', '2026-06-09 10:00'),
            self::round('r2', '2026-06-10 20:00'),
            self::round('r3', '2026-06-11 20:00'),
        ]);

        self::assertSame(WhenLabel::TODAY, $timeline->rounds[1]->when?->type);
        self::assertSame(0, $timeline->foldedCount, 'one past round folds nothing');
    }

    public function testAnEventThatIsOverOrNotStartedFoldsNothing(): void
    {
        $over = $this->build([self::round('r1', '2026-03-11 22:00'), self::round('r2', '2026-04-08 22:00'), self::round('r3', '2026-05-13 22:00')]);
        self::assertNull($over->nextRoundId);
        self::assertSame(0, $over->foldedCount);

        $ahead = $this->build([self::round('r1', '2026-07-11 22:00'), self::round('r2', '2026-08-08 22:00')]);
        self::assertSame([RoundStatus::Next, RoundStatus::Later], self::statuses($ahead));
        self::assertNull($ahead->rounds[0]->when, 'beyond 30 days');
    }

    public function testResultsLinksOnlyOnPublicPagesForRoundsWithSomethingToShow(): void
    {
        $rounds = [
            self::round('r1', '2026-03-11 22:00', slug: 'sprint-1'),
            self::round('r2', '2026-04-08 22:00', slug: 'sprint-2'),
            self::round('r3', '2026-05-13 22:00', slug: 'sprint-3', resultsPublished: true),
            self::round('r4', '2026-05-20 22:00'),
        ];
        $edition = new CompetitionReference('Season One', 'season-one', 'Sprint League', 'sprint-league');

        $timeline = $this->build($rounds, event: $edition, resultsPerRound: ['r1' => 4, 'r4' => 2]);

        self::assertSame(
            ['/series/sprint-league/season-one/results/sprint-1', null, '/series/sprint-league/season-one/results/sprint-3', null],
            array_map(static fn (TimelineRound $round): null|string => $round->resultsUrl, $timeline->rounds),
        );
        self::assertSame([false, false, true, false], array_map(static fn (TimelineRound $round): bool => $round->officialResults, $timeline->rounds));
        self::assertSame(3, $timeline->roundsWithResults);

        $notPublic = $this->build($rounds, event: $edition, isPublic: false, resultsPerRound: ['r1' => 4]);
        self::assertSame([null, null, null, null], array_map(static fn (TimelineRound $round): null|string => $round->resultsUrl, $notPublic->rounds));

        $oneTime = $this->build([self::round('r1', '2026-03-11 22:00', slug: 'final')], resultsPerRound: ['r1' => 1]);
        self::assertSame('/events/spring-open/results/final', $oneTime->rounds[0]->resultsUrl);
    }

    public function testAddMyTimeOnStartedRoundsWithTheOneVisiblePuzzle(): void
    {
        $rounds = [
            self::round('r1', '2026-03-11 22:00', puzzles: [self::puzzle('p1')]),
            self::round('r2', '2026-04-08 22:00', puzzles: [self::puzzle('p2'), self::puzzle('p3')]),
            self::round('r3', '2026-05-13 22:00', puzzles: [self::puzzle('p4', imageHidden: true)]),
            self::round('r4', '2026-07-15 22:00', puzzles: [self::puzzle('p5')]),
            self::round('r5', '2026-08-15 22:00'),
        ];

        $timeline = $this->build($rounds, canAddTime: true);

        self::assertSame(
            ['/puzzle-add/p1?competition=c-1', '/puzzle-add?competition=c-1', '/puzzle-add?competition=c-1', null, null],
            array_map(static fn (TimelineRound $round): null|string => $round->addTimeUrl, $timeline->rounds),
        );
        self::assertSame([true, true, true, true, false], array_map(static fn (TimelineRound $round): bool => $round->puzzlesAnnounced, $timeline->rounds));

        $guest = $this->build($rounds, canAddTime: false);
        self::assertSame([null, null, null, null, null], array_map(static fn (TimelineRound $round): null|string => $round->addTimeUrl, $guest->rounds));
    }

    public function testInPersonRoundsAndTheirZone(): void
    {
        $timeline = $this->build([
            self::round('fri', '2026-07-10 16:00', zone: 'Europe/Prague'),
            self::round('sat', '2026-07-11 08:00', zone: 'Europe/Prague'),
            self::round('sun', '2026-07-12 08:00', zone: 'Europe/Prague'),
        ], isOnline: false);

        self::assertSame(DateLeaf::TONE_IN_PERSON, $timeline->rounds[0]->leaf->tone);
        self::assertCount(1, $timeline->sessions, 'a Friday to Sunday championship is one session');
        self::assertSame('2026-07-10', $timeline->start?->format('Y-m-d'));
        self::assertSame('2026-07-12', $timeline->end?->format('Y-m-d'));
        self::assertSame('Europe/Prague', $timeline->zone);
    }

    public function testNoRoundsUsesTheEventsDates(): void
    {
        $utc = new DateTimeZone('UTC');
        $timeline = $this->build([], dateFrom: new DateTimeImmutable('2026-06-09 09:00', $utc), dateTo: new DateTimeImmutable('2026-06-11', $utc));

        self::assertFalse($timeline->hasRounds());
        self::assertSame('2026-06-09', $timeline->start?->format('Y-m-d'));
        self::assertSame('2026-06-11', $timeline->end?->format('Y-m-d'));
        self::assertTrue($timeline->isLive);
        self::assertNull($timeline->zone);

        $undated = $this->build([]);
        self::assertNull($undated->start);
        self::assertNull($undated->end);
        self::assertFalse($undated->isLive);
    }

    public function testALongSpanWithoutRoundsRunsUntilItsEndOnlyWhileItRuns(): void
    {
        $utc = new DateTimeZone('UTC');
        $day = static fn (string $date): DateTimeImmutable => new DateTimeImmutable($date, $utc);

        // NOW is 2026-06-10: started in March, ends in December
        self::assertTrue($this->build([], dateFrom: $day('2026-03-01'), dateTo: $day('2026-12-31'))->runsUntilEnd);
        // Its first and last day count
        self::assertTrue($this->build([], dateFrom: $day('2026-06-10'), dateTo: $day('2026-08-31'))->runsUntilEnd);
        self::assertTrue($this->build([], dateFrom: $day('2026-04-01'), dateTo: $day('2026-06-10'))->runsUntilEnd);
        // Not started yet, over, or not longer than 31 days: the plain date range
        self::assertFalse($this->build([], dateFrom: $day('2026-06-11'), dateTo: $day('2026-12-31'))->runsUntilEnd);
        self::assertFalse($this->build([], dateFrom: $day('2026-01-01'), dateTo: $day('2026-06-09'))->runsUntilEnd);
        self::assertFalse($this->build([], dateFrom: $day('2026-06-01'), dateTo: $day('2026-06-30'))->runsUntilEnd);
        // Rounds define the dates
        self::assertFalse($this->build([self::round('r1', '2026-06-10 10:00')], dateFrom: $day('2026-03-01'), dateTo: $day('2026-12-31'))->runsUntilEnd);
    }

    public function testRoundsInDifferentZonesAreMixed(): void
    {
        self::assertFalse($this->build([
            self::round('r1', '2026-07-10 16:00', zone: 'Europe/Prague'),
            self::round('r2', '2026-07-11 16:00', zone: 'Europe/Prague'),
        ])->mixedZones);

        self::assertTrue($this->build([
            self::round('r1', '2026-07-10 16:00', zone: 'Europe/Prague'),
            self::round('r2', '2026-07-11 16:00', zone: 'America/New_York'),
        ])->mixedZones);
    }

    /**
     * @param list<EditionRoundDetail> $rounds
     * @param array<string, int> $resultsPerRound
     */
    private function build(
        array $rounds,
        null|CompetitionReference $event = null,
        bool $isOnline = true,
        bool $isPublic = true,
        array $resultsPerRound = [],
        bool $canAddTime = false,
        null|DateTimeImmutable $dateFrom = null,
        null|DateTimeImmutable $dateTo = null,
    ): RoundsTimeline {
        return new RoundsTimelineBuilder(new PathUrlGenerator())->build(
            $event ?? new CompetitionReference('Spring Open', 'spring-open'),
            'c-1',
            $rounds,
            $isOnline,
            $isPublic,
            $resultsPerRound,
            $canAddTime,
            $dateFrom,
            $dateTo,
            new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')),
        );
    }

    /**
     * @param string $localStart in $zone
     * @param list<EditionRoundPuzzle> $puzzles
     */
    private static function round(
        string $id,
        string $localStart,
        int $minutes = 60,
        string $zone = 'America/New_York',
        null|string $slug = null,
        bool $resultsPublished = false,
        array $puzzles = [],
    ): EditionRoundDetail {
        return new EditionRoundDetail(
            id: $id,
            name: 'Round ' . $id,
            startsAt: new DateTimeImmutable($localStart, new DateTimeZone($zone))->setTimezone(new DateTimeZone('UTC')),
            minutesLimit: $minutes,
            category: RoundCategory::Solo,
            badgeBackgroundColor: null,
            badgeTextColor: null,
            puzzles: $puzzles,
            color: '#123456',
            textColor: '#ffffff',
            slug: $slug,
            timezone: $zone,
            resultsPublished: $resultsPublished,
        );
    }

    private static function puzzle(string $id, bool $imageHidden = false): EditionRoundPuzzle
    {
        return new EditionRoundPuzzle($id, 'Puzzle ' . $id, 500, $imageHidden ? null : 'p.jpg', 1.0, 'Brand', false, $imageHidden);
    }

    /**
     * @return list<RoundStatus>
     */
    private static function statuses(RoundsTimeline $timeline): array
    {
        return array_map(static fn (TimelineRound $round): RoundStatus => $round->status, $timeline->rounds);
    }
}
