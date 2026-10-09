<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Entity;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\RemovedResultSnapshot;
use SpeedPuzzling\Web\Value\SeriesEditionMatchKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The entity side of a series pick (docs/features/events-page/high-frequency-series.md "Data model"): nothing here is
 * flushed - the fixture player and puzzle are only read.
 */
final class PuzzleSolvingTimeSeriesPickTest extends KernelTestCase
{
    private Player $player;
    private Puzzle $puzzle;
    private CompetitionSeries $series;
    private Competition $edition;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $player = $entityManager->find(Player::class, PlayerFixture::PLAYER_REGULAR);
        $puzzle = $entityManager->find(Puzzle::class, PuzzleFixture::PUZZLE_500_01);
        self::assertNotNull($player);
        self::assertNotNull($puzzle);

        $this->player = $player;
        $this->puzzle = $puzzle;
        $this->series = self::series('Lantern Weekly Jam');
        $this->edition = self::edition($this->series, 'Jam No. 153');
    }

    public function testAnExplicitLinkHasNoEditionToResolve(): void
    {
        $time = $this->time(competition: $this->edition);

        $this->expectException(LogicException::class);
        $time->seriesEditionResolved($this->edition, SeriesEditionMatchKind::Puzzle);
    }

    public function testAnEditionOfAnotherSeriesIsRefused(): void
    {
        $time = $this->time(competitionSeries: $this->series);
        $otherEdition = self::edition(self::series('Moonlit Puzzle Sprint'), 'Sprint No. 1');

        $this->expectException(LogicException::class);
        $time->seriesEditionResolved($otherEdition, SeriesEditionMatchKind::Date);
    }

    public function testAnEditionWithoutHowItWasMatchedIsRefused(): void
    {
        $time = $this->time(competitionSeries: $this->series);

        $this->expectException(LogicException::class);
        $time->seriesEditionResolved($this->edition, null);
    }

    public function testHowItWasMatchedWithoutAnEditionIsRefused(): void
    {
        $time = $this->time(competitionSeries: $this->series);

        $this->expectException(LogicException::class);
        $time->seriesEditionResolved(null, SeriesEditionMatchKind::Puzzle);
    }

    public function testResolvingLinksTheEditionAndBackToSeriesLevel(): void
    {
        $time = $this->time(competitionSeries: $this->series);
        $time->popEvents();

        $time->seriesEditionResolved($this->edition, SeriesEditionMatchKind::Puzzle);
        self::assertSame($this->edition, $time->competition);
        self::assertSame(SeriesEditionMatchKind::Puzzle, $time->seriesEditionMatch);
        self::assertSame($this->series, $time->competitionSeries);

        $time->seriesEditionResolved(null, null);
        self::assertNull($time->competition);
        self::assertNull($time->seriesEditionMatch);
        self::assertSame($this->series, $time->competitionSeries);

        self::assertSame([], $time->popEvents(), 'Nothing about the time itself changed');
    }

    public function testASeriesPickStartsWithoutAnEdition(): void
    {
        $this->expectException(LogicException::class);

        $this->time(competition: $this->edition, competitionSeries: $this->series);
    }

    public function testModifySetsTheSeriesPickAndForgetsHowItWasMatched(): void
    {
        $time = $this->time(competitionSeries: $this->series);
        $time->seriesEditionResolved($this->edition, SeriesEditionMatchKind::Date);

        $this->modify($time, null, $this->series);
        self::assertSame($this->series, $time->competitionSeries);
        self::assertNull($time->competition);
        self::assertNull($time->seriesEditionMatch);

        // An explicit edition: the pick goes
        $this->modify($time, $this->edition, null);
        self::assertSame($this->edition, $time->competition);
        self::assertNull($time->competitionSeries);
        self::assertNull($time->seriesEditionMatch);
    }

    public function testModifyRefusesAnExplicitCompetitionWithASeries(): void
    {
        $time = $this->time();

        $this->expectException(LogicException::class);
        $this->modify($time, $this->edition, $this->series);
    }

    /**
     * P10: the whole link of a copy moves - competition, series pick and match kind together - only to a copy without
     * any event link.
     */
    public function testKeepingACopyTakesTheWholeLinkOnlyWithoutOne(): void
    {
        $copy = $this->time(competitionSeries: $this->series);
        $copy->seriesEditionResolved($this->edition, SeriesEditionMatchKind::Puzzle);

        $kept = $this->time();
        self::assertTrue($kept->takeOverFrom([$copy], $this->player, false));
        self::assertSame($this->edition, $kept->competition);
        self::assertSame($this->series, $kept->competitionSeries);
        self::assertSame(SeriesEditionMatchKind::Puzzle, $kept->seriesEditionMatch);

        // An explicit edition stays as it is - no other series' pick mixes into it
        $explicit = $this->time(competition: self::edition(self::series('Moonlit Puzzle Sprint'), 'Sprint No. 1'));
        self::assertFalse($explicit->takeOverFrom([$copy], $this->player, false));
        self::assertNull($explicit->competitionSeries);

        // A series-level pick has an event link too - an explicit copy's competition does not replace it
        $seriesLevel = $this->time(competitionSeries: $this->series);
        self::assertFalse($seriesLevel->takeOverFrom([$this->time(competition: $this->edition)], $this->player, false));
        self::assertNull($seriesLevel->competition);
        self::assertSame($this->series, $seriesLevel->competitionSeries);

        // A series-level copy hands over its pick
        $plain = $this->time();
        self::assertTrue($plain->takeOverFrom([$this->time(competitionSeries: $this->series)], $this->player, false));
        self::assertSame($this->series, $plain->competitionSeries);
        self::assertNull($plain->competition);
        self::assertNull($plain->seriesEditionMatch);
    }

    /**
     * P9: the snapshot of an automatically removed copy carries its series pick; Undo restores the pick (without the
     * edition - it is matched again).
     */
    public function testTheSnapshotCarriesTheSeriesPickAndRestoreTakesIt(): void
    {
        $time = $this->time(competitionSeries: $this->series);
        $time->seriesEditionResolved($this->edition, SeriesEditionMatchKind::Date);

        $snapshot = RemovedResultSnapshot::fromArray(RemovedResultSnapshot::of($time)->toArray());
        self::assertSame($this->series->id->toString(), $snapshot->competitionSeriesId);
        self::assertSame(SeriesEditionMatchKind::Date, $snapshot->seriesEditionMatch);
        self::assertSame($this->edition->id->toString(), $snapshot->competitionId);

        $restored = PuzzleSolvingTime::restore($snapshot, $this->player, $this->puzzle, null, null, competitionSeries: $this->series);
        self::assertSame($this->series, $restored->competitionSeries);
        self::assertNull($restored->competition);
        self::assertNull($restored->seriesEditionMatch);
    }

    public function testASnapshotFromBeforeSeriesPicksHasNone(): void
    {
        $data = RemovedResultSnapshot::of($this->time(competition: $this->edition))->toArray();
        unset($data['competition_series_id'], $data['series_edition_match']);

        $snapshot = RemovedResultSnapshot::fromArray($data);

        self::assertNull($snapshot->competitionSeriesId);
        self::assertNull($snapshot->seriesEditionMatch);
        self::assertSame($this->edition->id->toString(), $snapshot->competitionId);
    }

    public function testASeriesPickDoesNotMoveWithARound(): void
    {
        $time = $this->time(competitionSeries: $this->series);

        $this->expectException(LogicException::class);
        $time->competitionRoundMovedTo(self::edition($this->series, 'Jam No. 154'));
    }

    private function time(null|Competition $competition = null, null|CompetitionSeries $competitionSeries = null): PuzzleSolvingTime
    {
        return new PuzzleSolvingTime(
            Uuid::uuid7(),
            3900,
            $this->player,
            $this->puzzle,
            new DateTimeImmutable('2026-10-06 20:00:00'),
            false,
            null,
            new DateTimeImmutable('2026-10-05'),
            null,
            null,
            false,
            false,
            competition: $competition,
            competitionSeries: $competitionSeries,
        );
    }

    private function modify(PuzzleSolvingTime $time, null|Competition $competition, null|CompetitionSeries $competitionSeries): void
    {
        $time->modify(
            3900,
            null,
            null,
            new DateTimeImmutable('2026-10-05'),
            null,
            false,
            false,
            competition: $competition,
            puzzlingTeam: null,
            competitionSeries: $competitionSeries,
        );
    }

    private static function series(string $name): CompetitionSeries
    {
        return new CompetitionSeries(Uuid::uuid7(), $name, null, null, null, null, isOnline: true);
    }

    private static function edition(CompetitionSeries $series, string $name): Competition
    {
        return new Competition(
            id: Uuid::uuid7(),
            name: $name,
            slug: null,
            shortcut: null,
            logo: null,
            description: null,
            link: null,
            registrationLink: null,
            resultsLink: null,
            location: null,
            locationCountryCode: null,
            dateFrom: new DateTimeImmutable('2026-10-05'),
            dateTo: new DateTimeImmutable('2026-10-05'),
            tag: null,
            isOnline: true,
            series: $series,
        );
    }
}
