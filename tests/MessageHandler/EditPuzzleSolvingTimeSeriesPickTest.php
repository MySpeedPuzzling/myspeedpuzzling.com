<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\RejectCompetitionSeries;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Editing a time with a series pick (docs/features/events-page/high-frequency-series.md H4, scenario H12 12): every
 * save of a series pick is a fresh match; an explicit link stays explicit; the time's current series is accepted even
 * when it is no longer public.
 */
final class EditPuzzleSolvingTimeSeriesPickTest extends KernelTestCase
{
    private const string PLAYER = PlayerFixture::PLAYER_REGULAR_USER_ID;

    private SeriesEditionScenario $scenario;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scenario = new SeriesEditionScenario(self::getContainer());
    }

    public function testScenario12AnotherDayIsMatchedAgain(): void
    {
        $series = $this->scenario->series();
        $first = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $second = $this->scenario->edition($series, 'Jam No. 2', '2026-03-20');
        $time = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-03-02', seriesId: $series);
        self::assertSame([$first, 'date'], $this->editionOf($time));

        $this->edit($time, day: '2026-03-20', seriesId: $series);

        self::assertSame([$second, 'date'], $this->editionOf($time));
        self::assertSame($series, $this->scenario->link($time)['competition_series_id']);
    }

    public function testScenario12AnotherPuzzleIsMatchedAgain(): void
    {
        $series = $this->scenario->series();
        $copper = $this->scenario->puzzle('Copper Lighthouse');
        $starry = $this->scenario->puzzle('Starry Harbor');
        $first = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $second = $this->scenario->edition($series, 'Jam No. 2', '2026-03-20');
        $this->scenario->round($first, RoundCategory::Solo, '2026-03-02 19:00', puzzleIds: [$copper]);
        $secondRound = $this->scenario->round($second, RoundCategory::Solo, '2026-03-20 19:00', puzzleIds: [$starry]);
        $time = $this->scenario->addTime(self::PLAYER, $copper, '2026-03-10', seriesId: $series);
        self::assertSame([$first, 'puzzle'], $this->editionOf($time));

        $this->edit($time, day: '2026-03-10', seriesId: $series, puzzleId: $starry);

        self::assertSame([$second, 'puzzle'], $this->editionOf($time));
        self::assertSame($secondRound, $this->scenario->link($time)['competition_round_id']);
    }

    public function testScenario12AnotherGroupIsMatchedAgain(): void
    {
        $series = $this->scenario->series();
        $puzzle = $this->scenario->puzzle();
        $solo = $this->scenario->edition($series, 'Solo Jam', '2026-03-02');
        $pairs = $this->scenario->edition($series, 'Pairs Jam', '2026-03-05');
        $this->scenario->round($solo, RoundCategory::Solo, '2026-03-02 19:00', puzzleIds: [$puzzle]);
        $pairsRound = $this->scenario->round($pairs, RoundCategory::Duo, '2026-03-05 19:00', puzzleIds: [$puzzle]);
        $time = $this->scenario->addTime(self::PLAYER, $puzzle, '2026-03-03', seriesId: $series);
        self::assertSame([$solo, 'puzzle'], $this->editionOf($time));

        $this->edit($time, day: '2026-03-03', seriesId: $series, groupPlayers: ['Rowan Guest']);

        self::assertSame([$pairs, 'puzzle'], $this->editionOf($time));
        self::assertSame($pairsRound, $this->scenario->link($time)['competition_round_id']);
    }

    /**
     * H12 12: an explicit link stays explicit - nothing re-matches it.
     */
    public function testScenario12AnExplicitLinkStaysExplicit(): void
    {
        $series = $this->scenario->series();
        $explicit = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $this->scenario->edition($series, 'Jam No. 2', '2026-03-20');
        $time = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-03-02', competitionId: $explicit);

        $this->edit($time, day: '2026-03-20', competitionId: $explicit);

        self::assertSame(
            ['competition_id' => $explicit, 'competition_series_id' => null, 'series_edition_match' => null, 'competition_round_id' => null],
            $this->scenario->link($time),
        );
    }

    /**
     * Explicit <-> series pick: the field decides (the form's `edition:` / `series:` values).
     */
    public function testSwitchingBetweenAnExplicitEditionAndTheSeries(): void
    {
        $series = $this->scenario->series();
        $first = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $second = $this->scenario->edition($series, 'Jam No. 2', '2026-03-20');
        $time = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-03-20', competitionId: $first);

        $this->edit($time, day: '2026-03-20', seriesId: $series);
        self::assertSame([$second, 'date'], $this->editionOf($time));
        self::assertSame($series, $this->scenario->link($time)['competition_series_id']);

        $this->edit($time, day: '2026-03-20', competitionId: $first);
        self::assertSame(
            ['competition_id' => $first, 'competition_series_id' => null, 'series_edition_match' => null, 'competition_round_id' => null],
            $this->scenario->link($time),
        );
    }

    /**
     * Include-current: the series the time is in now is accepted even when it is no longer public - its series pick,
     * or the series of the edition it is linked to. Another hidden series is not.
     */
    public function testTheCurrentSeriesIsAcceptedWhenNoLongerPublic(): void
    {
        $series = $this->scenario->series();
        $edition = $this->scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $pick = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-03-02', seriesId: $series);
        $explicit = $this->scenario->addTime(PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID, $this->scenario->puzzle(), '2026-03-02', competitionId: $edition);
        $this->scenario->dispatch(new RejectCompetitionSeries($series, PlayerFixture::PLAYER_ADMIN, 'Not a puzzle event'));

        $this->edit($pick, day: '2026-03-02', seriesId: $series);
        self::assertSame(
            ['competition_id' => null, 'competition_series_id' => $series, 'series_edition_match' => null, 'competition_round_id' => null],
            $this->scenario->link($pick),
        );

        $this->edit($explicit, day: '2026-03-02', seriesId: $series, userId: PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID);
        self::assertSame($series, $this->scenario->link($explicit)['competition_series_id']);

        $logs = new TestHandler();
        self::getContainer()->get('logger')->pushHandler($logs);
        $hidden = $this->scenario->series('Harbor Puzzle Club Nights', public: false);

        $this->edit($pick, day: '2026-03-02', seriesId: $hidden);
        self::assertNull($this->scenario->link($pick)['competition_series_id']);
        self::assertCount(1, array_filter(
            $logs->getRecords(),
            static fn (LogRecord $record): bool => $record->level === Level::Warning && str_starts_with($record->message, 'Solving time saved without series'),
        ));
    }

    /**
     * @param list<string> $groupPlayers
     */
    private function edit(
        string $timeId,
        string $day,
        null|string $seriesId = null,
        null|string $competitionId = null,
        null|string $puzzleId = null,
        array $groupPlayers = [],
        string $userId = self::PLAYER,
    ): void {
        $this->scenario->dispatch(new EditPuzzleSolvingTime(
            currentUserId: $userId,
            puzzleSolvingTimeId: $timeId,
            competitionId: $competitionId,
            time: '01:05:00',
            comment: null,
            groupPlayers: $groupPlayers,
            finishedAt: new DateTimeImmutable($day),
            finishedPuzzlesPhoto: null,
            firstAttempt: false,
            unboxed: false,
            puzzleId: $puzzleId,
            seriesId: $seriesId,
        ));
    }

    /**
     * @return array{?string, ?string} competition_id, series_edition_match
     */
    private function editionOf(string $timeId): array
    {
        $link = $this->scenario->link($timeId);

        return [$link['competition_id'], $link['series_edition_match']];
    }
}
